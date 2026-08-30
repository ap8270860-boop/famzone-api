#!/usr/bin/env python3
"""Star, pin and forward — wire them into the models, service and routes.

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
            sys.exit(f'{path}: anchor missing ->\n{old[:200]}')

        s = s.replace(old, new, 1)

    write(path, s)
    changed.append(path)
    print(f'{path}: patched')


# =========================================================== Conversation

patch('app/Models/Conversation.php', [
    (
        """    /**
     * @return BelongsTo<Message, Conversation>
     */
    public function lastMessage(): BelongsTo""",
        """    /**
     * The message pinned in this thread, shared by everyone in it.
     *
     * withTrashed so a pinned message that is later deleted shows as a
     * tombstone rather than the banner silently emptying — the service
     * clears the pin deliberately instead.
     *
     * @return BelongsTo<Message, Conversation>
     */
    public function pinnedMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'pinned_message_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Message, Conversation>
     */
    public function lastMessage(): BelongsTo""",
    ),
    (
        "            'last_message_at' => 'datetime',",
        "            'last_message_at' => 'datetime',\n            'pinned_at' => 'datetime',",
    ),
], marker='public function pinnedMessage()')


# ================================================================ Message

patch('app/Models/Message.php', [
    (
        "        return ['seq' => 'integer', 'edited_at' => 'datetime'];",
        "        return [\n"
        "            'seq' => 'integer',\n"
        "            'forwarded' => 'boolean',\n"
        "            'edited_at' => 'datetime',\n"
        "        ];",
    ),
], marker="'forwarded' => 'boolean'")


# ============================================================ ChatService

patch('app/Services/Chat/ChatService.php', [
    # presentMessage gains an optional starred set
    (
        """    public function presentMessage(Message $message): array
    {
        $deleted = $message->trashed();""",
        """    public function presentMessage(Message $message, ?array $starred = null): array
    {
        $deleted = $message->trashed();""",
    ),
    (
        "            'deleted' => $deleted,\n",
        "            'deleted' => $deleted,\n"
        "            'forwarded' => (bool) $message->forwarded,\n"
        "\n"
        "            /*\n"
        "             | Whether the viewer starred it.\n"
        "             |\n"
        "             | Passed in as a set for the whole page rather than\n"
        "             | queried per message — a scrollback of forty would\n"
        "             | otherwise be forty queries for a boolean. Null means\n"
        "             | the caller has no viewer (a broadcast, say), and a\n"
        "             | message nobody is looking at is starred by nobody.\n"
        "             */\n"
        "            'starred' => $starred !== null && isset($starred[$message->id]),\n",
    ),

    # helpers the action service needs
    (
        """    /**
     * @return array<string, mixed>
     */
    private function presentPerson(User $viewer, User $person): array""",
        """    /**
     * The other person in a direct thread, summarised.
     *
     * Public because the Starred screen lists messages from many threads and
     * has to say which one each came from.
     *
     * @return array<string, mixed>|null
     */
    public function otherPersonSummary(User $viewer, Conversation $conversation): ?array
    {
        $other = $this->otherParticipant($conversation, $viewer);

        return $other === null ? null : $this->presentPerson($viewer, $other->user);
    }

    /**
     * Move the sender's watermarks to a message they just wrote, and give
     * everybody else one unread.
     *
     * Extracted so forwarding does the same bookkeeping as sending. A
     * forwarded message that left the sender's own unread count sitting at
     * one would put a badge on a conversation they were just looking at.
     */
    public function markSenderCaughtUp(Conversation $conversation, User $sender, int $seq): void
    {
        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $sender->id)
            ->update([
                'last_read_seq' => $seq,
                'last_delivered_seq' => $seq,
                'unread_count' => 0,
                'updated_at' => now(),
            ]);

        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $sender->id)
            ->whereNull('left_at')
            ->update([
                'unread_count' => DB::raw('unread_count + 1'),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPerson(User $viewer, User $person): array""",
    ),

    # announce becomes public so forwarding can use it
    (
        "    private function announce(Conversation $conversation, Message $message): void",
        "    public function announce(Conversation $conversation, Message $message): void",
    ),

    # the pinned message on the conversation payload
    (
        "            'blocked' => $other !== null\n                && isset($this->wall($me)[$other->user_id]),",
        "            'blocked' => $other !== null\n"
        "                && isset($this->wall($me)[$other->user_id]),\n"
        "\n"
        "            // Shared by both people, unlike a star.\n"
        "            'pinned_message' => $conversation->pinnedMessage === null\n"
        "                ? null\n"
        "                : $this->presentMessage($conversation->pinnedMessage),",
    ),

    # eager loads
    (
        "            ->with([\n"
        "                'participants.user',\n"
        "                'lastMessage.sender:id,uuid',\n"
        "                'lastMessage.attachment',\n"
        "            ])",
        "            ->with([\n"
        "                'participants.user',\n"
        "                'lastMessage.sender:id,uuid',\n"
        "                'lastMessage.attachment',\n"
        "                'pinnedMessage.sender:id,uuid',\n"
        "            ])",
    ),
    (
        "        $conversation = Conversation::with(['participants.user', 'lastMessage.sender:id,uuid'])\n"
        "            ->where('uuid', $uuid)",
        "        $conversation = Conversation::with([\n"
        "            'participants.user',\n"
        "            'lastMessage.sender:id,uuid',\n"
        "            'pinnedMessage.sender:id,uuid',\n"
        "        ])\n"
        "            ->where('uuid', $uuid)",
    ),
], marker='markSenderCaughtUp')


# ================================================================ routes

patch('routes/api.php', [
    (
        """        Route::post('messages/{uuid}/react', [V1Controller::class, 'reactToMessage'])""",
        """        /*
         | Starred messages. Private to the caller — nothing about a star is
         | ever broadcast, and the other person cannot tell.
         |
         | Before messages/{uuid}, so the literal segment is not swallowed.
         */
        Route::get('starred-messages', [V1Controller::class, 'starredMessages'])
            ->name('messages.starred');

        Route::post('messages/{uuid}/star', [V1Controller::class, 'starMessage'])
            ->middleware('throttle:120,1')
            ->name('messages.star');

        Route::post('messages/{uuid}/forward', [V1Controller::class, 'forwardMessage'])
            ->middleware('throttle:30,1')
            ->name('messages.forward');

        Route::post('messages/{uuid}/react', [V1Controller::class, 'reactToMessage'])""",
    ),
    (
        """            Route::post('accept', [V1Controller::class, 'acceptConversation'])->name('accept');""",
        """            Route::post('accept', [V1Controller::class, 'acceptConversation'])->name('accept');

            /*
             | Pinning. One message at a time, shared by both people, so this
             | is pin, replace and unpin in a single write.
             */
            Route::post('pin', [V1Controller::class, 'pinMessage'])
                ->middleware('throttle:60,1')
                ->name('pin');""",
    ),
], marker='messages.starred')


# ============================================================ controller

patch('app/Http/Controllers/Api/V1/V1Controller.php', [
    (
        "use App\\Events\\Chat\\MessageReacted;",
        "use App\\Events\\Chat\\ConversationPinned;\nuse App\\Events\\Chat\\MessageReacted;",
    ),
    (
        "use App\\Http\\Requests\\Api\\V1\\Chat\\ReactRequest;",
        "use App\\Http\\Requests\\Api\\V1\\Chat\\ForwardRequest;\n"
        "use App\\Http\\Requests\\Api\\V1\\Chat\\PinRequest;\n"
        "use App\\Http\\Requests\\Api\\V1\\Chat\\ReactRequest;",
    ),
    (
        "use App\\Services\\Chat\\ChatService;",
        "use App\\Services\\Chat\\ChatService;\n"
        "use App\\Services\\Chat\\MessageActionService;",
    ),
    (
        "        private readonly ReactionService $reactions,\n    ) {",
        "        private readonly ReactionService $reactions,\n"
        "        private readonly MessageActionService $messageActions,\n"
        "    ) {",
    ),
    (
        "    private function findMessage(string $uuid): Message",
        """    /**
     * POST /api/v1/messages/{uuid}/star
     *
     * Toggles. Private to the caller: nothing is broadcast, and the other
     * person in the thread has no way to learn you kept something.
     */
    public function starMessage(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $message = $this->findMessage($uuid);

        // Membership check — a message id alone must not be enough.
        $this->chat->findConversation($me, $message->conversation->uuid);

        $starred = $this->messageActions->toggleStar($me, $message);

        return $this->ok(
            ['starred' => $starred],
            $starred ? 'Starred.' : 'Removed from starred.',
        );
    }

    /**
     * GET /api/v1/starred-messages?page=1
     *
     * Only from threads the caller is still in — leaving a conversation, or
     * being blocked out of one, takes its messages out of here too.
     */
    public function starredMessages(Request $request): JsonResponse
    {
        return $this->ok(
            $this->messageActions->starred(
                $request->user(),
                max(1, (int) $request->integer('page', 1)),
            ),
            'OK',
        );
    }

    /**
     * POST /api/v1/messages/{uuid}/forward   {conversation_ids: []}
     *
     * A new message in each target, never a reference to the original: the
     * recipient must not gain access to the thread it came from, and a shared
     * row would mean deleting the original deletes every forward of it.
     */
    public function forwardMessage(ForwardRequest $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        $sent = $this->messageActions->forward(
            $me,
            $this->findMessage($uuid),
            $request->targets(),
        );

        // Announced after every write has committed, the same as an ordinary
        // send — each target thread gets its own message and inbox events.
        foreach ($sent as $message) {
            $this->chat->announce($message->conversation, $message);
        }

        return $this->ok(
            ['count' => count($sent)],
            count($sent) === 1 ? 'Forwarded.' : 'Forwarded to '.count($sent).' chats.',
        );
    }

    /**
     * POST /api/v1/conversations/{uuid}/pin   {message_id}
     *
     * Shared by both people, so either can set or clear it and both banners
     * move together. `message_id: null` unpins.
     */
    public function pinMessage(PinRequest $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $conversation = $this->chat->findConversation($me, $uuid);

        $messageUuid = $request->messageUuid();

        $updated = $this->messageActions->pin(
            $me,
            $conversation,
            $messageUuid === null ? null : $this->findMessage($messageUuid),
        );

        ConversationPinned::dispatch($updated);

        return $this->ok(
            $this->chat->presentConversation($me, $updated),
            $messageUuid === null ? 'Unpinned.' : 'Pinned.',
        );
    }

    private function findMessage(string $uuid): Message""",
    ),
], marker='starMessage')


print('\ndone: ' + (', '.join(changed) if changed else 'nothing to do'))
