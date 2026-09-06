<?php

namespace App\Events\Location;

use App\Models\User;
use App\Services\Location\LocationService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody moved.
 *
 * Goes to one channel named after the person being watched, not to each
 * watcher's mailbox. Six family members watching one phone is then one frame
 * out of the server rather than six, and adding a seventh watcher costs the
 * broadcaster nothing.
 *
 * ShouldBroadcastNow, unlike every other event in this codebase.
 *
 * A chat message is allowed to arrive a second late; the queue is worth it
 * there because it keeps the sender's request fast. A position is the
 * opposite: it is worthless a second late, it is superseded by the next one
 * anyway, and the whole feature is judged on whether the dot moves smoothly.
 * Putting these behind a worker adds queue latency to every fix and — worse —
 * lets fixes overtake each other when two workers pick up neighbouring jobs,
 * which on a map looks exactly like the marker jumping backwards.
 *
 * The cost of that choice is that the ping request now waits on Reverb.
 * That is why the payload is small and why LocationService swallows a
 * broadcast failure rather than failing the ping: a dead socket must
 * degrade to "the map stops updating", never to "the phone stops
 * recording".
 */
class LocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly User $user)
    {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('location.'.$this->user->uuid)];
    }

    public function broadcastAs(): string
    {
        return 'location.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return app(LocationService::class)->presentPosition($this->user);
    }
}
