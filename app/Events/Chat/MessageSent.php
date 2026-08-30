<?php

namespace App\Events\Chat;

use App\Models\Message;
use App\Services\Chat\ChatService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message landed in a conversation.
 *
 * Goes only to the thread's own channel, so it reaches the two people in it
 * and nobody else — and within those two, only a client with that
 * conversation's screen open is subscribed. The inbox and the badge are fed
 * separately by [InboxUpdated], because they are a different question with a
 * different lifetime.
 *
 * ShouldBroadcast, not ShouldBroadcastNow: this goes on the queue so the
 * sender's own request does not wait on a round trip to Reverb, and a Reverb
 * that is down delays delivery instead of failing the send.
 */
class MessageSent implements ShouldBroadcast
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

    /**
     * A stable wire name.
     *
     * Without this the event name on the wire is the fully-qualified class
     * name, and moving the class between namespaces breaks every deployed
     * client — including the ones on phones nobody can update.
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * Its own queue, away from everything else.
     *
     * A chat message is the most latency-sensitive job in the system and one
     * of the cheapest to run. Sharing the default queue would put it behind
     * whatever slow work happens to be in front of it — a thumbnail, a batch
     * of push notifications — and a message that takes eight seconds to
     * appear reads as a broken app, not a busy one.
     */
    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // The same shape the REST endpoint returns, from the same method, so
        // a message that arrives over the socket and one that arrives over
        // HTTP are indistinguishable to the client. Never a serialised
        // model — that is how internal ids end up on the wire.
        return app(ChatService::class)->presentMessage(
            $this->message->loadMissing([
                'sender:id,uuid',
                'attachment',
                'replyTo.sender:id,uuid',
                'reactions.user:id,uuid',
            ]),
        );
    }

    /**
     * Deliberately broadcast to everyone, including the sender.
     *
     * The plan called for ->toOthers() here, which suppresses the echo to
     * whoever sent it. Two reasons not to:
     *
     *  - toOthers() depends on the client passing its socket id up with every
     *    send, which is plumbing through the HTTP layer for no gain.
     *  - the sender's own echo is already harmless. The client keys messages
     *    on client_uuid, so the echo resolves to the optimistic bubble that
     *    is already on screen and changes nothing.
     *
     * Broadcasting to everyone is also what multi-device support would need,
     * so this is one fewer thing to unpick if that day comes.
     */
}
