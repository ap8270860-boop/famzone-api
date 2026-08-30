<?php

namespace App\Events\Chat;

use App\Models\Conversation;
use App\Services\Chat\ChatService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The pinned message changed.
 *
 * A pin is shared by everyone in the thread, unlike a star, so both banners
 * have to move together. Broadcast on the conversation channel because it is
 * a fact about the conversation rather than about either person.
 */
class ConversationPinned implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly Conversation $conversation)
    {
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
        return 'conversation.pinned';
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
        $conversation = $this->conversation->fresh(['pinnedMessage.sender:id,uuid']);

        return [
            'conversation_id' => $this->conversation->uuid,
            // Null when the pin was cleared, which is what tells the client to
            // take the banner down.
            'pinned_message' => $conversation?->pinnedMessage === null
                ? null
                : app(ChatService::class)->presentMessage($conversation->pinnedMessage),
        ];
    }
}
