<?php

namespace App\Events\Chat;

use App\Models\Message;
use App\Services\Chat\ReactionService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The reactions on a message changed.
 *
 * Carries the whole set for that message rather than the one that changed.
 * A delta would need the client to already agree with the server about what
 * was there before, and two people reacting at once would leave the two
 * screens disagreeing with no way to notice. The full set is a handful of
 * bytes and is self-correcting.
 */
class MessageReacted implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly Message $message)
    {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->message->conversation->uuid)];
    }

    public function broadcastAs(): string
    {
        return 'message.reacted';
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
        /*
         | Reactions are grouped without a viewer, so `mine` cannot be set
         | here — this one payload goes to both people, and each client
         | decides which reaction is its own from the user ids in the set.
         |
         | Sending a per-viewer payload would mean one event per participant,
         | which is real cost for a fact the client can work out for free.
         */
        return [
            'message_id' => $this->message->uuid,
            'reactions' => app(ReactionService::class)->present(
                $this->message->fresh(['reactions.user:id,uuid']),
            ),
        ];
    }
}
