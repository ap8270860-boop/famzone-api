#!/usr/bin/env python3
"""Pin a chat, mute it, mark it unread, clear it.

Everything here is one person's own view of a thread, so every write lands on
their participant row and nothing is broadcast.

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
        "            'unread_count' => 'integer',",
        "            'unread_count' => 'integer',\n            'marked_unread' => 'boolean',",
    ),
    (
        "            'muted_until' => 'datetime',",
        "            'muted_until' => 'datetime',\n            'pinned_at' => 'datetime',",
    ),
    (
        """    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }""",
        """    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }""",
    ),
], marker='isPinned')


# ============================================================ ChatService

patch('app/Services/Chat/ChatService.php', [
    # Pinned threads first, then newest.
    (
        """            ->orderByDesc('last_message_at')
            ->orderByDesc('id');""",
        """            /*
             | Pinned chats first.
             |
             | Ordered by a correlated subquery against this person's own
             | participant row, because a pin belongs to one reader: it
             | cannot be a column on the conversation, and a plain join would
             | bring the other participant's row along with it.
             |
             | MySQL sorts NULLs last on a descending order, so every
             | unpinned thread falls in behind every pinned one for free.
             */
            ->orderByDesc(ConversationParticipant::query()
                ->select('pinned_at')
                ->whereColumn('conversation_id', 'conversations.id')
                ->where('user_id', $me->id)
                ->limit(1))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');""",
    ),

    # The badge counts a manually-unread thread as one.
    (
        "            ->selectRaw('state, SUM(unread_count) as unread, COUNT(*) as threads')",
        "            /*\n"
        "             | A thread somebody marked unread counts as one.\n"
        "             |\n"
        "             | Otherwise the row shows a dot the app badge does not\n"
        "             | know about, and the two disagree on the home screen.\n"
        "             */\n"
        "            ->selectRaw(\n"
        "                'state, SUM(CASE WHEN unread_count > 0 THEN unread_count'\n"
        "                .' WHEN marked_unread = 1 THEN 1 ELSE 0 END) as unread,'\n"
        "                .' COUNT(*) as threads'\n"
        "            )",
    ),

    # This person's own view of the thread.
    (
        "            'muted' => (bool) $mine?->isMuted(),",
        "            'muted' => (bool) $mine?->isMuted(),\n"
        "            'muted_until' => $mine?->muted_until?->toIso8601String(),\n"
        "\n"
        "            /*\n"
        "             | Mine alone, both of them.\n"
        "             |\n"
        "             | Pinning a chat to the top of my list says nothing about\n"
        "             | where it sits in theirs — unlike a pinned message,\n"
        "             | which is shared and lives on the conversation.\n"
        "             */\n"
        "            'pinned' => $mine?->pinned_at !== null,\n"
        "            'marked_unread' => (bool) ($mine?->marked_unread ?? false),",
    ),

    # A pin banner must not quote a message this person cleared.
    (
        """            // Shared by both people, unlike a star.
            'pinned_message' => $conversation->pinnedMessage === null
                ? null
                : $this->presentMessage($conversation->pinnedMessage),""",
        """            /*
             | Shared by both people, unlike a star — but still not shown to
             | somebody who deleted or cleared that message on their own
             | side. A banner quoting a message you deliberately removed is
             | worse than no banner at all.
             */
            'pinned_message' => $conversation->pinnedMessage === null
                || $this->hasHidden($me, $conversation->pinnedMessage->id)
                ? null
                : $this->presentMessage($conversation->pinnedMessage),""",
    ),
], marker="'marked_unread' =>")


# ========================================================= ReceiptService

patch('app/Services/Chat/ReceiptService.php', [
    (
        """                $changes['unread_count'] = Message::where('conversation_id', $conversation->id)
                    ->where('sender_id', '!=', $me->id)
                    ->where('seq', '>', $message->seq)
                    ->count();
            }""",
        """                $changes['unread_count'] = Message::where('conversation_id', $conversation->id)
                    ->where('sender_id', '!=', $me->id)
                    ->where('seq', '>', $message->seq)
                    ->count();

                // Actually reading the thread undoes "mark as unread". Same
                // write, so the flag cannot survive a read by being cleared
                // in a second query that fails.
                $changes['marked_unread'] = false;
            }""",
    ),
], marker="marked_unread")


# ================================================================ routes

patch('routes/api.php', [
    (
        """            Route::post('pin', [V1Controller::class, 'pinMessage'])
                ->middleware('throttle:60,1')
                ->name('pin');""",
        """            Route::post('pin', [V1Controller::class, 'pinMessage'])
                ->middleware('throttle:60,1')
                ->name('pin');

            /*
             | This person's own view of the thread.
             |
             | Nothing here is broadcast and nothing is visible to the other
             | person: each one writes a column on the caller's participant
             | row. `pin-chat` is deliberately not `pin` — that one is the
             | shared pinned message, which is a different thing entirely.
             */
            Route::post('pin-chat', [V1Controller::class, 'pinConversation'])
                ->middleware('throttle:60,1')
                ->name('pin_chat');

            Route::post('mute', [V1Controller::class, 'muteConversation'])
                ->middleware('throttle:60,1')
                ->name('mute');

            Route::post('unread', [V1Controller::class, 'markConversationUnread'])
                ->middleware('throttle:60,1')
                ->name('unread');

            // Tighter: emptying a long thread writes a row per message.
            Route::post('clear', [V1Controller::class, 'clearConversation'])
                ->middleware('throttle:20,1')
                ->name('clear');""",
    ),
], marker='pin_chat')


# ============================================================ controller

patch('app/Http/Controllers/Api/V1/V1Controller.php', [
    (
        "use App\\Services\\Chat\\ReceiptService;",
        "use App\\Services\\Chat\\ReceiptService;\nuse App\\Services\\Chat\\ThreadSettingsService;",
    ),
    (
        "        private readonly MessageActionService $messageActions,\n    ) {",
        "        private readonly MessageActionService $messageActions,\n"
        "        private readonly ThreadSettingsService $threads,\n"
        "    ) {",
    ),
    (
        """    /**
     * POST /api/v1/messages/{uuid}/hide""",
        """    /**
     * POST /api/v1/conversations/{uuid}/pin-chat
     *
     * Pin the thread to the top of my own inbox. Toggles, and is invisible
     * to the other person — this is not the shared pinned message.
     */
    public function pinConversation(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        $pinned = $this->threads->togglePin(
            $me,
            $this->chat->findConversation($me, $uuid),
        );

        return $this->ok(
            ['pinned' => $pinned],
            $pinned ? 'Pinned to top.' : 'Unpinned.',
        );
    }

    /**
     * POST /api/v1/conversations/{uuid}/mute   {muted, hours?}
     *
     * Notifications only. Messages still arrive and the thread still counts
     * as unread; muting is about whether the phone makes a noise.
     */
    public function muteConversation(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $conversation = $this->chat->findConversation($me, $uuid);

        $muted = $request->boolean('muted', true);
        $hours = $request->input('hours');

        $until = $this->threads->mute(
            $me,
            $conversation,
            $muted,
            is_numeric($hours) ? (int) $hours : null,
        );

        return $this->ok(
            ['muted' => $muted, 'muted_until' => $until?->toIso8601String()],
            $muted ? 'Muted.' : 'Unmuted.',
        );
    }

    /**
     * POST /api/v1/conversations/{uuid}/unread
     *
     * Make it look unread again. A flag of my own — the read watermark does
     * not move, so their ticks stay exactly as they were.
     */
    public function markConversationUnread(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        $this->threads->markUnread(
            $me,
            $this->chat->findConversation($me, $uuid),
        );

        return $this->ok(['marked_unread' => true], 'Marked as unread.');
    }

    /**
     * POST /api/v1/conversations/{uuid}/clear
     *
     * Empty the thread on my side. Every message hidden for me, exactly as
     * if I had deleted each one for myself; the thread itself stays in the
     * list and the other person keeps everything.
     */
    public function clearConversation(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        $this->threads->clear(
            $me,
            $this->chat->findConversation($me, $uuid),
        );

        return $this->ok(null, 'Chat cleared.');
    }

    /**
     * POST /api/v1/messages/{uuid}/hide""",
    ),
], marker='pinConversation')


print('\ndone: ' + (', '.join(changed) if changed else 'nothing to do'))
