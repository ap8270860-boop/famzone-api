#!/usr/bin/env python3
"""Reactions — wire the table, service and event into the chat.

Run from the famzone-api repo root. Idempotent.
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
        # Guarded. An unguarded str.replace fails silently when the anchor has
        # drifted, and the script reports success having changed nothing.
        if old not in s:
            sys.exit(f'{path}: anchor missing ->\n{old[:200]}')

        s = s.replace(old, new, 1)

    write(path, s)
    changed.append(path)
    print(f'{path}: patched')


# ================================================================ Message

patch('app/Models/Message.php', [
    (
        """    /**
     * @return \\Illuminate\\Database\\Eloquent\\Relations\\HasOne<MessageAttachment>
     */""",
        """    /**
     * @return HasMany<MessageReaction>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    /**
     * @return \\Illuminate\\Database\\Eloquent\\Relations\\HasOne<MessageAttachment>
     */""",
    ),
], marker='public function reactions()')


# ============================================================ ChatService

patch('app/Services/Chat/ChatService.php', [
    (
        """        private readonly AttachmentService $attachments,
    ) {""",
        """        private readonly AttachmentService $attachments,
        private readonly ReactionService $reactions,
    ) {""",
    ),
    (
        "            'reply_to' => $this->presentQuote($message->replyTo),",
        "            'reply_to' => $this->presentQuote($message->replyTo),\n"
        "\n"
        "            // Dropped with the body once deleted — a tombstone with\n"
        "            // six laughing faces on it is nobody's idea of good taste.\n"
        "            'reactions' => $deleted\n"
        "                ? []\n"
        "                : $this->reactions->present($message),",
    ),
    (
        "            ->with([\n"
        "                'sender:id,uuid',\n"
        "                'attachment',\n"
        "                'replyTo.sender:id,uuid',\n"
        "            ]);",
        "            ->with([\n"
        "                'sender:id,uuid',\n"
        "                'attachment',\n"
        "                'replyTo.sender:id,uuid',\n"
        "                'reactions.user:id,uuid',\n"
        "            ]);",
    ),
    (
        "            ->with(['sender:id,uuid', 'attachment', 'replyTo.sender:id,uuid'])",
        "            ->with([\n"
        "                'sender:id,uuid',\n"
        "                'attachment',\n"
        "                'replyTo.sender:id,uuid',\n"
        "                'reactions.user:id,uuid',\n"
        "            ])",
    ),
], marker='ReactionService')


# ============================================================ MessageSent

patch('app/Events/Chat/MessageSent.php', [
    (
        "                'replyTo.sender:id,uuid',\n            ]),",
        "                'replyTo.sender:id,uuid',\n"
        "                'reactions.user:id,uuid',\n"
        "            ]),",
    ),
], marker='reactions.user')


# ================================================================ routes

patch('routes/api.php', [
    (
        """        Route::delete('messages/{uuid}', [V1Controller::class, 'deleteMessage'])
            ->name('messages.destroy');""",
        """        Route::delete('messages/{uuid}', [V1Controller::class, 'deleteMessage'])
            ->name('messages.destroy');

        /*
         | Reactions. One row per person per message, so this is add, change
         | and remove in a single endpoint — `emoji: null` takes yours off.
         */
        Route::post('messages/{uuid}/react', [V1Controller::class, 'reactToMessage'])
            ->middleware('throttle:120,1')
            ->name('messages.react');""",
    ),
], marker='messages.react')


# ============================================================ controller

patch('app/Http/Controllers/Api/V1/V1Controller.php', [
    (
        "use App\\Http\\Requests\\Api\\V1\\Chat\\ReceiptRequest;",
        "use App\\Http\\Requests\\Api\\V1\\Chat\\ReactRequest;\n"
        "use App\\Http\\Requests\\Api\\V1\\Chat\\ReceiptRequest;",
    ),
    (
        "use App\\Services\\Chat\\PresenceService;",
        "use App\\Services\\Chat\\PresenceService;\n"
        "use App\\Services\\Chat\\ReactionService;",
    ),
    (
        "        private readonly AttachmentService $attachments,\n    ) {",
        "        private readonly AttachmentService $attachments,\n"
        "        private readonly ReactionService $reactions,\n"
        "    ) {",
    ),
    (
        "    private function findMessage(string $uuid): Message",
        """    /**
     * POST /api/v1/messages/{uuid}/react   {emoji}
     *
     * Add, change or remove a reaction. One endpoint for all three, because
     * the unique index means it is one row either way — `emoji: null` takes
     * yours off, and sending the same emoji twice does the same thing.
     *
     * Answers with the whole message so the caller can repaint the bubble
     * from one response, and broadcasts the same set to the other person.
     */
    public function reactToMessage(ReactRequest $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $message = $this->findMessage($uuid);

        // Membership check. Without it anybody holding a message id could
        // react into a conversation they have never been part of.
        $conversation = $this->chat->findConversation(
            $me,
            $message->conversation->uuid,
        );

        abort_if($conversation->id !== $message->conversation_id, 404);

        $updated = $this->reactions->react($me, $message, $request->emoji());

        MessageReacted::dispatch($updated);

        return $this->ok(
            $this->chat->presentMessage(
                $updated->loadMissing(['sender:id,uuid', 'attachment', 'replyTo.sender:id,uuid']),
            ),
            'OK',
        );
    }

    private function findMessage(string $uuid): Message""",
    ),
    (
        # The controller has no Events imports yet, so this goes at the top of
        # the block rather than beside a sibling.
        "use App\\Http\\Controllers\\Controller;",
        "use App\\Events\\Chat\\MessageReacted;\n"
        "use App\\Http\\Controllers\\Controller;",
    ),
], marker='reactToMessage')


print('\ndone: ' + (', '.join(changed) if changed else 'nothing to do'))
