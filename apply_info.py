#!/usr/bin/env python3
"""Message info — when it was delivered, and when it was read.

Records a row each time a watermark advances, and adds the endpoint the info
sheet reads.

Run from the famzone-api repo root. Idempotent, and every anchor is guarded.
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


# ========================================================= ReceiptService

patch('app/Services/Chat/ReceiptService.php', [
    (
        "use App\\Models\\Message;\n",
        "use App\\Models\\Message;\nuse App\\Models\\ReceiptMark;\n",
    ),

    # A mark, in the same transaction as the watermark it records.
    (
        """            $participant->forceFill($changes)->save();
        });""",
        """            $participant->forceFill($changes)->save();

            /*
             | And a note of when it moved.
             |
             | Inside the transaction on purpose: a watermark without its mark
             | is a message whose info screen can never explain itself, and
             | two separate writes is exactly how that happens.
             |
             | One row per advance, not per message — sixty unread messages
             | read at once produce a single row saying "reached seq 60 at
             | 10:57", which is the true answer for all sixty of them.
             */
            $mark = new ReceiptMark();

            $mark->conversation_id = $conversation->id;
            $mark->user_id = $me->id;
            $mark->kind = $read ? ReceiptMark::KIND_READ : ReceiptMark::KIND_DELIVERED;
            $mark->seq = $message->seq;
            $mark->marked_at = now();
            $mark->save();

            // Reading implies delivery, and the info screen shows both lines.
            // Without this a message read straight off the socket would show
            // a read time and no delivery time.
            if ($read && isset($changes['last_delivered_seq'])) {
                $delivered = new ReceiptMark();

                $delivered->conversation_id = $conversation->id;
                $delivered->user_id = $me->id;
                $delivered->kind = ReceiptMark::KIND_DELIVERED;
                $delivered->seq = $message->seq;
                $delivered->marked_at = now();
                $delivered->save();
            }
        });""",
    ),

    # The lookup itself.
    (
        """    /**
     * Tell the other person the ticks moved.""",
        """    /**
     * When one message reached the other person, and when they read it.
     *
     * Only ever asked about your own messages — "when did they read mine" is
     * a question about them, and the answer belongs to whoever wrote the
     * message rather than to anyone who can see it.
     *
     * @return array<string, mixed>
     */
    public function info(User $me, Conversation $conversation, Message $message): array
    {
        abort_unless(
            $message->sender_id === $me->id,
            403,
            'Message info is only available for your own messages.',
        );

        $other = $this->chat->otherParticipant($conversation, $me);

        if ($other === null) {
            return [
                'message_id' => $message->uuid,
                'seq' => $message->seq,
                'sent_at' => $message->created_at->toIso8601String(),
                'delivered_at' => null,
                'read_at' => null,
                'read_receipts_hidden' => false,
            ];
        }

        $delivered = ReceiptMark::whenReached(
            $conversation->id,
            $other->user_id,
            ReceiptMark::KIND_DELIVERED,
            $message->seq,
        );

        /*
         | Read receipts are a setting, and it has to hold here as well as on
         | the ticks.
         |
         | Somebody who has turned them off reports no read time at all —
         | reporting one here would be a way to read the setting around the
         | back, which is worse than the ticks lying because it comes with a
         | timestamp attached.
         */
        $hidden = ! $other->user->show_read_receipts;

        $readAt = $hidden ? null : ReceiptMark::whenReached(
            $conversation->id,
            $other->user_id,
            ReceiptMark::KIND_READ,
            $message->seq,
        );

        return [
            'message_id' => $message->uuid,
            'seq' => $message->seq,
            'sent_at' => $message->created_at->toIso8601String(),

            /*
             | Null has two meanings and the client says which: not yet, or
             | before this feature existed. Marks only go back as far as the
             | migration, so a message older than it has watermarks past it
             | and no mark to explain them.
             */
            'delivered_at' => $delivered?->toIso8601String(),
            'read_at' => $readAt?->toIso8601String(),

            // Whether the watermark says it happened, even when no mark
            // records the moment. This is what tells the client to say "no
            // exact time" rather than "not delivered".
            'delivered' => $other->last_delivered_seq >= $message->seq,
            'read' => ! $hidden && $other->last_read_seq >= $message->seq,

            'read_receipts_hidden' => $hidden,
        ];
    }

    /**
     * Tell the other person the ticks moved.""",
    ),
], marker='public function info(')


# ================================================================ routes

patch('routes/api.php', [
    (
        """        Route::post('messages/{uuid}/hide', [V1Controller::class, 'hideMessage'])""",
        """        /*
         | Message info. Your own messages only — see ReceiptService::info.
         |
         | Before messages/{uuid}/hide only for readability; the segments do
         | not collide.
         */
        Route::get('messages/{uuid}/info', [V1Controller::class, 'messageInfo'])
            ->middleware('throttle:120,1')
            ->name('messages.info');

        Route::post('messages/{uuid}/hide', [V1Controller::class, 'hideMessage'])""",
    ),
], marker='messages.info')


# ============================================================ controller

patch('app/Http/Controllers/Api/V1/V1Controller.php', [
    (
        """    /**
     * POST /api/v1/messages/{uuid}/hide""",
        """    /**
     * GET /api/v1/messages/{uuid}/info
     *
     * When it reached them, and when they read it. Only for messages you
     * wrote yourself.
     */
    public function messageInfo(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $message = $this->findMessage($uuid);

        // Membership check — a message id alone must not be enough.
        $conversation = $this->chat->findConversation($me, $message->conversation->uuid);

        return $this->ok($this->receipts->info($me, $conversation, $message), 'OK');
    }

    /**
     * POST /api/v1/messages/{uuid}/hide""",
    ),
], marker='messageInfo')


print('\ndone: ' + (', '.join(changed) if changed else 'nothing to do'))
