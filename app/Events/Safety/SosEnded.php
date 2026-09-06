<?php

namespace App\Events\Safety;

use App\Models\SosAlert;
use App\Models\User;
use App\Services\Safety\SosService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * It is over.
 *
 * Every bit as urgent as the alarm itself, and for the same reason: a family
 * looking at a full-screen alert should stop looking at it the instant it
 * stops being true. An alarm that keeps ringing after the person is safe is
 * how people learn to ignore alarms.
 *
 * Carries the closing status, which is not cosmetic — "she cancelled it" and
 * "it was a pocket press" call for completely different reactions from
 * whoever is reading.
 */
class SosEnded implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<int, User>  $family
     */
    public function __construct(
        public readonly SosAlert $alert,
        public readonly array $family,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            fn (User $member) => new PrivateChannel('user.'.$member->uuid),
            $this->family,
        );
    }

    public function broadcastAs(): string
    {
        return 'sos.ended';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $person = $this->alert->user;

        return [
            'alert' => app(SosService::class)->present($this->alert),
            'person' => [
                'id' => $person?->uuid,
                'name' => $person?->name,
                'avatar_url' => $person?->avatar_url,
            ],
        ];
    }
}
