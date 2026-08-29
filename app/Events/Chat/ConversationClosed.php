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
 * A thread is no longer available to somebody.
 *
 * Sent to both people when a block goes up. It exists because channel
 * authorisation runs once, on subscribe: a client that was already listening
 * to a conversation when the block landed would keep receiving messages on it
 * indefinitely, because nothing ever asks the server again.
 *
 * The fix is to tell the client to let go. Reverb can also terminate a user's
 * connections outright, but that is a blunt instrument — it would drop every
 * channel they hold, including conversations with people who have nothing to
 * do with this — and an unsubscribe is the version that is precise.
 *
 * Goes on each person's own mailbox channel rather than the conversation
 * channel, deliberately: the conversation channel is the thing being closed,
 * and a message telling you to leave a room should not arrive through the
 * door being shut.
 */
class ConversationClosed implements ShouldBroadcast
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
        return 'conversation.closed';
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
        // Only the id. Both sides already hold whatever they need to know
        // about this thread, and a block is not the moment to send somebody a
        // fresh copy of the conversation they have just lost access to.
        return ['conversation_id' => $this->conversation->uuid];
    }
}
