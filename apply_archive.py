#!/usr/bin/env python3
"""Archiving a chat.

Run from the famzone-api repo root. Idempotent, and every anchor is guarded:
an unguarded str.replace fails silently when a file has drifted, and the
script then reports success having changed nothing.
"""

import io
import sys

changed = []


def read(p):
    return io.open(p, encoding='utf-8').read()


def write(p, s):
    io.open(p, 'w', encoding='utf-8', newline='').write(s)


def patch(path, pairs, marker):
    s = read(path)

    if marker in s:
        print(f'{path}: already patched')

        return

    for old, new in pairs:
        if old not in s:
            sys.exit(f'{path}: anchor missing ->\n{old[:240]}')

        s = s.replace(old, new, 1)

    write(path, s)
    changed.append(path)
    print(f'{path}: patched')


# ================================================ ConversationParticipant

patch('app/Models/ConversationParticipant.php', [
    (
        "            'pinned_at' => 'datetime',",
        "            'pinned_at' => 'datetime',\n            'archived_at' => 'datetime',",
    ),
    (
        """    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }""",
        """    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }""",
    ),
], marker='isArchived')


# ============================================================ ChatService

patch('app/Services/Chat/ChatService.php', [
    # The inbox is now two lists behind one query.
    (
        """    public function inbox(
        User $me,
        string $state = ConversationParticipant::STATE_ACCEPTED,
        int $page = 1,
        int $perPage = self::INBOX_PER_PAGE,
    ): array {
        $perPage = max(1, min(50, $perPage));

        $query = Conversation::query()
            ->whereIn('id', ConversationParticipant::query()
                ->select('conversation_id')
                ->where('user_id', $me->id)
                ->where('state', $state)
                ->whereNull('left_at'))""",
        """    public function inbox(
        User $me,
        string $state = ConversationParticipant::STATE_ACCEPTED,
        int $page = 1,
        int $perPage = self::INBOX_PER_PAGE,
        bool $archived = false,
    ): array {
        $perPage = max(1, min(50, $perPage));

        $query = Conversation::query()
            ->whereIn('id', ConversationParticipant::query()
                ->select('conversation_id')
                ->where('user_id', $me->id)
                ->where('state', $state)
                ->whereNull('left_at')
                /*
                 | Archived threads are not gone, they are elsewhere.
                 |
                 | The same endpoint serves both lists rather than a second
                 | one that would duplicate the blocking rule, the
                 | never-written-in rule, the eager loads and the ordering —
                 | four things that must not be allowed to drift apart.
                 */
                ->when(
                    $archived,
                    fn (Builder $q) => $q->whereNotNull('archived_at'),
                    fn (Builder $q) => $q->whereNull('archived_at'),
                ))""",
    ),

    # Archived threads keep their own counts, out of the main badge.
    (
        """        $rows = ConversationParticipant::query()
            ->where('user_id', $me->id)
            ->whereNull('left_at')""",
        """        $rows = ConversationParticipant::query()
            ->where('user_id', $me->id)
            ->whereNull('left_at')
            /*
             | Archived threads are deliberately outside the badge.
             |
             | Putting a chat away is a statement that it should stop asking
             | for attention; a number on the home screen it still feeds
             | would make the gesture pointless.
             */
            ->whereNull('archived_at')""",
    ),
    (
        """        return [
            'unread' => (int) ($accepted->unread ?? 0),
            'threads' => (int) ($accepted->threads ?? 0),
            'requests' => (int) ($pending->threads ?? 0),
        ];""",
        """        // One extra count, for the Archived row at the top of the inbox.
        // It shows how many are in there, not how many are unread — see
        // above for why archived threads do not carry an unread number.
        $archived = ConversationParticipant::query()
            ->where('user_id', $me->id)
            ->whereNull('left_at')
            ->where('state', ConversationParticipant::STATE_ACCEPTED)
            ->whereNotNull('archived_at')
            ->count();

        return [
            'unread' => (int) ($accepted->unread ?? 0),
            'threads' => (int) ($accepted->threads ?? 0),
            'requests' => (int) ($pending->threads ?? 0),
            'archived' => $archived,
        ];""",
    ),

    # Presented, so the menu knows which way its label should read.
    (
        "            'pinned' => $mine?->pinned_at !== null,",
        "            'pinned' => $mine?->pinned_at !== null,\n"
        "            'archived' => $mine?->archived_at !== null,",
    ),
], marker="'archived' => \\$mine?->archived_at !== null,".replace('\\', ''))


# ================================================= ThreadSettingsService

patch('app/Services/Chat/ThreadSettingsService.php', [
    (
        """    /**
     * Silence the thread, or let it speak again.""",
        """    /**
     * Put the thread away, or bring it back.
     *
     * Archiving is not deleting and not muting: the thread keeps every
     * message, keeps notifying, and comes back the moment this person opens
     * it. It is only about which list it appears in.
     *
     * A new message does *not* pull it out again, deliberately. Somebody who
     * archived a chat has said where they want it; a thread that unarchives
     * itself the moment it is used is a setting that undoes itself.
     */
    public function toggleArchive(User $me, Conversation $conversation): bool
    {
        $participant = $this->chat->participantOrFail($conversation, $me);

        $archived = $participant->archived_at === null;

        $participant->forceFill([
            'archived_at' => $archived ? now() : null,

            /*
             | Putting a chat away un-pins it.
             |
             | The two are contradictory instructions — one says hold this at
             | the top of my list, the other says take it out of my list —
             | and honouring both would mean a pinned chat sitting at the top
             | of the Archived screen for no reason anybody could explain.
             */
            'pinned_at' => $archived ? null : $participant->pinned_at,
        ])->save();

        return $archived;
    }

    /**
     * Silence the thread, or let it speak again.""",
    ),
], marker='toggleArchive')


# ================================================================ routes

patch('routes/api.php', [
    (
        """            Route::post('pin-chat', [V1Controller::class, 'pinConversation'])
                ->middleware('throttle:60,1')
                ->name('pin_chat');""",
        """            Route::post('pin-chat', [V1Controller::class, 'pinConversation'])
                ->middleware('throttle:60,1')
                ->name('pin_chat');

            Route::post('archive', [V1Controller::class, 'archiveConversation'])
                ->middleware('throttle:60,1')
                ->name('archive');""",
    ),
], marker="'archiveConversation'")


# ============================================================ controller

patch('app/Http/Controllers/Api/V1/V1Controller.php', [
    # ?archived=1 switches the same endpoint to the other list.
    (
        """                $state,
                max(1, (int) $request->integer('page', 1)),
                (int) $request->integer('per_page', ChatService::INBOX_PER_PAGE),
            ),""",
        """                $state,
                max(1, (int) $request->integer('page', 1)),
                (int) $request->integer('per_page', ChatService::INBOX_PER_PAGE),
                // The same list, filtered the other way. Archived threads
                // are excluded from the ordinary inbox by default.
                $request->boolean('archived'),
            ),""",
    ),
    (
        """    /**
     * POST /api/v1/conversations/{uuid}/mute""",
        """    /**
     * POST /api/v1/conversations/{uuid}/archive
     *
     * Put the thread away, or bring it back. Toggles, and is invisible to
     * the other person — archiving is about which of my lists it appears in,
     * nothing more.
     */
    public function archiveConversation(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        $archived = $this->threads->toggleArchive(
            $me,
            $this->chat->findConversation($me, $uuid),
        );

        return $this->ok(
            ['archived' => $archived],
            $archived ? 'Archived.' : 'Moved back to your chats.',
        );
    }

    /**
     * POST /api/v1/conversations/{uuid}/mute""",
    ),
], marker='archiveConversation')


print('\ndone: ' + (', '.join(changed) if changed else 'nothing to do'))
