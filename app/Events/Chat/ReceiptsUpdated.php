<?php

namespace App\Events\Chat;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody's watermark moved: the ticks change.
 *
 * Two integers reach the other person's screen and every bubble at or below
 * them repaints. That is what makes opening a thread with sixty unread
 * messages turn the sender's whole column blue at once, rather than rippling
 * upward one tick at a time.
 *
 * The reader's own unread count is deliberately not in the payload. It is
 * their business, not the other person's, and this event goes to a channel
 * they both sit on.
 */
class ReceiptsUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Sequence numbers are passed as scalars rather than re-read on the
     * worker, on purpose.
     *
     * A watermark can move again between this event being queued and the job
     * running, and two jobs could then complete out of order — the client
     * would receive a lower value last and the ticks would go backwards. The
     * numbers captured at write time are the ones that were true, and the
     * client applies whichever is highest.
     */
    public function __construct(
        public readonly Conversation $conversation,
        public readonly User $reader,
        public readonly int $lastReadSeq,
        public readonly int $lastDeliveredSeq,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->conversation->uuid)];
    }

    public function broadcastAs(): string
    {
        return 'receipts.updated';
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->uuid,
            'user_id' => $this->reader->uuid,
            'last_read_seq' => $this->lastReadSeq,
            'last_delivered_seq' => $this->lastDeliveredSeq,
        ];
    }
}
