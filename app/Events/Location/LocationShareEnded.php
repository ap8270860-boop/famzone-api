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
 * A share is over.
 *
 * The server stops broadcasting positions the moment the share ends, so this
 * event is not what makes the tracking stop — it is what makes the map stop
 * *lying*. Without it the last known pin would sit there indefinitely,
 * looking live, and a stale dot on a safety app is worse than no dot: people
 * act on it.
 *
 * Sent to the viewers the share had, which is why they are passed in rather
 * than recomputed — by the time this is handled the share is already dead
 * and would resolve to nobody.
 */
class LocationShareEnded implements ShouldBroadcast
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
        return 'location.share.ended';
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
            'share' => app(LocationService::class)->presentShare($this->share),
        ];
    }
}
