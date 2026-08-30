#!/usr/bin/env python3
"""Replies — wire up the reply_to_id column that has been sitting unused.

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
     * The message this one is answering, if any.
     *
     * withTrashed on purpose: a reply must outlive what it replied to. The
     * quote becomes a tombstone rather than vanishing, because a reply with
     * nothing above it reads as a non-sequitur.
     *
     * @return BelongsTo<Message, Message>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id')->withTrashed();
    }

    /**
     * @return \\Illuminate\\Database\\Eloquent\\Relations\\HasOne<MessageAttachment>
     */""",
    ),
], marker='public function replyTo()')


# ===================================================== SendMessageRequest

patch('app/Http/Requests/Api/V1/Chat/SendMessageRequest.php', [
    (
        """            // The id returned by POST /uploads.""",
        """            /*
             | The message being answered. Its public id, and it must belong
             | to this same conversation — the service checks that, because a
             | reply pointing into somebody else's thread would leak a line of
             | it into this one.
             */
            'reply_to_id' => ['sometimes', 'nullable', 'uuid'],

            // The id returned by POST /uploads.""",
    ),
    (
        """            'upload_id' => $this->input('upload_id'),
        ];""",
        """            'upload_id' => $this->input('upload_id'),
            'reply_to_id' => $this->input('reply_to_id'),
        ];""",
    ),
    (
        "     * @return array{client_uuid: string, type: string, body: ?string, upload_id: ?string}",
        "     * @return array{client_uuid: string, type: string, body: ?string, upload_id: ?string, reply_to_id: ?string}",
    ),
], marker='reply_to_id')


# ============================================================ ChatService

patch('app/Services/Chat/ChatService.php', [
    (
        "use Illuminate\\Support\\Facades\\DB;",
        "use Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Str;",
    ),

    # --- resolve the parent inside the send transaction ----------------
    (
        """        $message->conversation_id = $conversation->id;
        $message->sender_id = $sender->id;
        $message->seq = $seq;
        $message->save();""",
        """        $message->conversation_id = $conversation->id;
        $message->sender_id = $sender->id;
        $message->seq = $seq;

        /*
         | Resolve the message being replied to, scoped to this conversation.
         |
         | The scope is the security check, not a convenience: without it a
         | crafted reply_to_id would quote a line out of a thread the sender
         | has no access to, and the quote is rendered verbatim on both
         | screens.
         |
         | withTrashed, because replying to something that was then deleted is
         | ordinary and the reply should still stand.
         */
        if (filled($payload['reply_to_id'] ?? null)) {
            $parent = Message::withTrashed()
                ->where('conversation_id', $conversation->id)
                ->where('uuid', $payload['reply_to_id'])
                ->first();

            abort_if($parent === null, 422, 'That message is not in this conversation.');

            $message->reply_to_id = $parent->id;
        }

        $message->save();""",
    ),

    # --- present it ----------------------------------------------------
    (
        """            'attachment' => $deleted || $message->attachment === null
                ? null
                : $this->attachments->present($message->attachment),""",
        """            'attachment' => $deleted || $message->attachment === null
                ? null
                : $this->attachments->present($message->attachment),

            'reply_to' => $this->presentQuote($message->replyTo),""",
    ),
    (
        """    /**
     * @return array<string, mixed>
     */
    public function presentConversation(User $me, Conversation $conversation): array""",
        """    /**
     * A one-line version of a quoted message.
     *
     * Deliberately not the full DTO. A quote needs enough to recognise the
     * message and nothing more, and nesting whole messages inside messages
     * would let one deep reply chain drag half a conversation onto the wire.
     *
     * @return array<string, mixed>|null
     */
    private function presentQuote(?Message $quoted): ?array
    {
        if ($quoted === null) {
            return null;
        }

        $gone = $quoted->trashed();

        return [
            'id' => $quoted->uuid,
            'sender_id' => $quoted->sender?->uuid,
            'type' => $gone ? Message::TYPE_TEXT : $quoted->type,
            // Truncated: a quote is a pointer, and a 4000-character one would
            // bury the reply underneath it.
            'body' => $gone ? null : Str::limit((string) $quoted->body, 140),
            'deleted' => $gone,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentConversation(User $me, Conversation $conversation): array""",
    ),

    # --- eager load, everywhere messages are read ----------------------
    (
        "            ->with(['sender:id,uuid', 'attachment']);",
        "            ->with([\n"
        "                'sender:id,uuid',\n"
        "                'attachment',\n"
        "                'replyTo.sender:id,uuid',\n"
        "            ]);",
    ),
    (
        """        return Message::withTrashed()
            ->with(['sender:id,uuid', 'attachment'])""",
        """        return Message::withTrashed()
            ->with(['sender:id,uuid', 'attachment', 'replyTo.sender:id,uuid'])""",
    ),
], marker='presentQuote')


# ============================================================ MessageSent

patch('app/Events/Chat/MessageSent.php', [
    (
        "            $this->message->loadMissing(['sender:id,uuid', 'attachment']),",
        "            $this->message->loadMissing([\n"
        "                'sender:id,uuid',\n"
        "                'attachment',\n"
        "                'replyTo.sender:id,uuid',\n"
        "            ]),",
    ),
], marker='replyTo')


# ============================================================ controller

patch('app/Http/Controllers/Api/V1/V1Controller.php', [
    (
        "        $message = Message::with(['sender:id,uuid', 'attachment'])\n"
        "            ->where('uuid', $uuid)\n"
        "            ->first();",
        "        $message = Message::with([\n"
        "            'sender:id,uuid',\n"
        "            'attachment',\n"
        "            'replyTo.sender:id,uuid',\n"
        "        ])->where('uuid', $uuid)->first();",
    ),
], marker="'replyTo.sender:id,uuid',\n        ])->where")


print('\ndone: ' + (', '.join(changed) if changed else 'nothing to do'))
