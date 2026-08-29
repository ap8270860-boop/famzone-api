<?php

namespace App\Events\Chat;

use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\ChatService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A thread moved, for one particular person.
 *
 * Goes to that person's own mailbox channel, which their app holds open from
 * sign-in to sign-out. This is what keeps the inbox ordered and the badge
 * honest while no chat screen is open — the conversation channel cannot do
 * it, because nobody is subscribed to a thread they are not looking at.
 *
 * One event per recipient rather than one shared event, because the payload
 * genuinely differs: unread counts, read watermarks and the accepted/pending
 * state are per person. A single broadcast would have to carry everyone's
 * state to everyone, which is both wasteful and a small privacy leak.
 */
class InboxUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly User $recipient,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->recipient->uuid)];
    }

    public function broadcastAs(): string
    {
        return 'inbox.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        /*
         | Relations are loaded here rather than carried on the event.
         |
         | SerializesModels stores only ids and re-queries on the worker, so
         | this runs after the transaction that caused it has committed and
         | reads the settled numbers. Passing a hydrated model through the
         | queue would freeze whatever the counts happened to be mid-write.
         */
        $conversation = $this->conversation
            ->load(['participants.user', 'lastMessage.sender:id,uuid']);

        return app(ChatService::class)
            ->presentConversation($this->recipient, $conversation);
    }
}
