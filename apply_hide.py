#!/usr/bin/env python3
"""Delete for me — wire message_hides through the service, routes and controller.

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


# ============================================================ ChatService

patch('app/Services/Chat/ChatService.php', [
    (
        "use App\\Models\\MessageAttachment;\n",
        "use App\\Models\\MessageAttachment;\nuse App\\Models\\MessageHide;\n",
    ),

    # A hidden message is not in your history. Filtered in the query rather
    # than after the fetch, or a page of forty could come back holding twenty.
    (
        """        $query = $conversation->messages()
            ->withTrashed()
            ->with([
                'sender:id,uuid',
                'attachment',
                'replyTo.sender:id,uuid',
                'reactions.user:id,uuid',
            ]);""",
        """        $query = $conversation->messages()
            ->withTrashed()
            /*
             | Deleted for me.
             |
             | Filtered in the query, not after it: a page is forty rows, and
             | dropping hidden ones afterwards would hand back short pages
             | that get shorter the more somebody has deleted.
             */
            ->whereNotIn('id', MessageHide::idsFor($me->id))
            ->with([
                'sender:id,uuid',
                'attachment',
                'replyTo.sender:id,uuid',
                'reactions.user:id,uuid',
            ]);""",
    ),

    # One query per inbox page instead of one per row.
    (
        """        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $page * $perPage < $total,
            'conversations' => $conversations
                ->map(fn (Conversation $c) => $this->presentConversation($me, $c))
                ->all(),
        ];""",
        """        // One query for the whole page: which of these last messages the
        // viewer has deleted for themselves. Without it every row would ask
        // on its own, and twenty rows would be twenty queries for a boolean.
        $this->warmHidden($me, $conversations->pluck('last_message_id')->all());

        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $page * $perPage < $total,
            'conversations' => $conversations
                ->map(fn (Conversation $c) => $this->presentConversation($me, $c))
                ->all(),
        ];""",
    ),

    # The helpers, and the inbox preview that respects a hide.
    (
        """    /**
     * @return array<string, mixed>
     */
    public function presentConversation(User $me, Conversation $conversation): array
    {
        $mine = $conversation->participants->firstWhere('user_id', $me->id);
        $other = $conversation->participants->firstWhere(
            fn (ConversationParticipant $p) => $p->user_id !== $me->id,
        );
""",
        """    /**
     * Which of these messages the viewer has deleted for themselves.
     *
     * Public so the inbox can fill the cache for a whole page in one query
     * before it presents any row.
     *
     * @param  array<int, int|null>  $messageIds
     */
    public function warmHidden(User $me, array $messageIds): void
    {
        $ids = array_values(array_unique(array_filter($messageIds)));

        if ($ids === []) {
            return;
        }

        $hidden = MessageHide::where('user_id', $me->id)
            ->whereIn('message_id', $ids)
            ->pluck('message_id')
            ->all();

        foreach ($ids as $id) {
            $this->hidden[$me->id.':'.$id] = in_array($id, $hidden, true);
        }
    }

    private function hasHidden(User $me, int $messageId): bool
    {
        $key = $me->id.':'.$messageId;

        if (! array_key_exists($key, $this->hidden)) {
            $this->warmHidden($me, [$messageId]);
        }

        return $this->hidden[$key] ?? false;
    }

    /**
     * The newest message in this thread the viewer has not hidden.
     *
     * Almost always the conversation's own last message, with no query at
     * all. It only reaches for the database when that message is one this
     * person deleted for themselves — rare, and the alternative is an inbox
     * row previewing a message you just deleted, which is the kind of bug
     * people screenshot.
     */
    private function visibleLastMessage(User $me, Conversation $conversation): ?Message
    {
        $last = $conversation->lastMessage;

        if ($last === null || ! $this->hasHidden($me, $last->id)) {
            return $last;
        }

        return $conversation->messages()
            ->withTrashed()
            ->whereNotIn('id', MessageHide::idsFor($me->id))
            ->with(['sender:id,uuid', 'attachment'])
            ->orderByDesc('seq')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentConversation(User $me, Conversation $conversation): array
    {
        $mine = $conversation->participants->firstWhere('user_id', $me->id);
        $other = $conversation->participants->firstWhere(
            fn (ConversationParticipant $p) => $p->user_id !== $me->id,
        );

        $last = $this->visibleLastMessage($me, $conversation);
""",
    ),
    (
        """            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'last_message' => $conversation->lastMessage
                ? $this->presentMessage($conversation->lastMessage)
                : null,""",
        """            // Both read off the message actually being shown, so a row
            // whose newest message is hidden reports the one it fell back to
            // rather than a timestamp for something invisible.
            'last_message_at' => ($last?->created_at ?? $conversation->last_message_at)
                ?->toIso8601String(),
            'last_message' => $last === null ? null : $this->presentMessage($last),""",
    ),

    # The per-request cache itself.
    (
        "    /** Scrollback page size. */\n    public const PER_PAGE = 40;",
        "    /** Scrollback page size. */\n"
        "    public const PER_PAGE = 40;\n"
        "\n"
        "    /**\n"
        "     * Hidden-message answers already fetched this request, keyed\n"
        "     * `userId:messageId`. A hide is permanent, so nothing here can go\n"
        "     * stale within the life of one request.\n"
        "     *\n"
        "     * @var array<string, bool>\n"
        "     */\n"
        "    private array $hidden = [];",
    ),
], marker='visibleLastMessage')


# =================================================== MessageActionService

patch('app/Services/Chat/MessageActionService.php', [
    (
        "use App\\Models\\MessageAttachment;\n",
        "use App\\Models\\MessageAttachment;\nuse App\\Models\\MessageHide;\n",
    ),
    (
        """    /**
     * Which of these messages this person has starred.""",
        """    /**
     * Delete for me.
     *
     * The message is untouched: it stays in the thread for everybody else,
     * because one reader wanting it off their own screen says nothing about
     * anyone else's copy. That is the entire difference between this and
     * delete for everyone, and it is why this is a row of its own rather
     * than a flag on the message.
     *
     * Nothing is broadcast. The other person must not be able to tell.
     */
    public function hideForMe(User $user, Message $message): void
    {
        try {
            $hide = new MessageHide();

            $hide->message_id = $message->id;
            $hide->user_id = $user->id;
            $hide->created_at = now();
            $hide->save();
        } catch (QueryException $e) {
            // Already hidden. Two taps in flight, or a retry after a timeout
            // — either way the message is gone from their screen, which is
            // what they asked for.
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }
        }
    }

    /**
     * Which of these messages this person has starred.""",
    ),
    (
        """            ->whereIn('id', MessageStar::where('user_id', $user->id)->select('message_id'))""",
        """            ->whereIn('id', MessageStar::where('user_id', $user->id)->select('message_id'))
            /*
             | Not the ones they deleted for themselves.
             |
             | Otherwise Starred becomes a way to keep reading a message you
             | deliberately removed from the thread.
             */
            ->whereNotIn('id', MessageHide::idsFor($user->id))""",
    ),
], marker='hideForMe')


# ================================================================ routes

patch('routes/api.php', [
    (
        """        Route::post('messages/{uuid}/star', [V1Controller::class, 'starMessage'])""",
        """        /*
         | Delete for me. Private to the caller, nothing broadcast — the other
         | person keeps their copy and cannot tell.
         */
        Route::post('messages/{uuid}/hide', [V1Controller::class, 'hideMessage'])
            ->middleware('throttle:120,1')
            ->name('messages.hide');

        Route::post('messages/{uuid}/star', [V1Controller::class, 'starMessage'])""",
    ),
], marker='messages.hide')


# ============================================================ controller

patch('app/Http/Controllers/Api/V1/V1Controller.php', [
    (
        """    /**
     * POST /api/v1/messages/{uuid}/star""",
        """    /**
     * POST /api/v1/messages/{uuid}/hide
     *
     * Delete for me. Works on anybody's message, unlike delete for everyone
     * — removing something from your own screen needs no permission from the
     * person who wrote it.
     */
    public function hideMessage(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $message = $this->findMessage($uuid);

        // Membership check — a message id alone must not be enough.
        $this->chat->findConversation($me, $message->conversation->uuid);

        $this->messageActions->hideForMe($me, $message);

        return $this->ok(null, 'Deleted for you.');
    }

    /**
     * POST /api/v1/messages/{uuid}/star""",
    ),
], marker='hideMessage')


print('\ndone: ' + (', '.join(changed) if changed else 'nothing to do'))
