<?php

namespace App\Events\Location;

use App\Models\LocationShare;
use App\Models\User;
use App\Services\Location\LocationService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody started sharing with you.
 *
 * Goes to each viewer's own mailbox rather than to the location channel,
 * because it has to reach people who are not subscribed to that channel yet
 * — being told to subscribe is the entire point of this event.
 *
 * Queued, unlike the position updates it precedes. It happens once per
 * share rather than once per fix, and a second's delay before a new pin
 * appears is not something anybody can perceive.
 */
class LocationShareStarted implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<int, User>  $viewers
     */
    public function __construct(
        public readonly LocationShare $share,
        public readonly array $viewers,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            fn (User $viewer) => new PrivateChannel('user.'.$viewer->uuid),
            $this->viewers,
        );
    }

    public function broadcastAs(): string
    {
        return 'location.share.started';
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
        $service = app(LocationService::class);

        $sharer = $this->share->user ?? $this->share->user()->first();

        return [
            'share' => $service->presentShare($this->share),

            /*
             | Who, by name, in the event itself.
             |
             | The payload used to carry a share and a position and nothing
             | about the person, which meant a client seeing somebody for the
             | first time had to make a round trip before it could render
             | anything — and a banner saying "somebody started sharing" while
             | it waits is worse than no banner.
             |
             | Three fields, not a full profile. Enough for a banner and a
             | marker; anything more belongs on the screen that opens next.
             */
            'user' => $sharer === null ? null : [
                'id' => $sharer->uuid,
                'name' => $sharer->name,
                'username' => $sharer->username,
                'avatar_url' => $sharer->avatar_url,
            ],

            /*
             | The first position ships with the announcement.
             |
             | Without it the client would show a pin with no coordinates
             | until the next ping arrives, which on a stationary phone can
             | be a full minute of an empty map after tapping "share".
             */
            'position' => $service->presentPosition($this->share->user),
        ];
    }
}
