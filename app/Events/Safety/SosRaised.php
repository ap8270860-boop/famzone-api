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
 * Somebody in your family has raised an alarm.
 *
 * The most important frame this system will ever send, and the only one where
 * a second of delay is a real cost rather than a theoretical one — so
 * ShouldBroadcastNow, not the queue. A chat message can wait behind a
 * thumbnail job; this cannot wait behind anything.
 *
 * The caller wraps the dispatch, so a Reverb that is down or slow degrades to
 * "the family finds out when they next open the app" rather than to a failed
 * request. The alert row is already committed before this runs.
 *
 * Sent to each family member's own mailbox rather than a shared channel,
 * because there is no room for a family to stand in — the audience is
 * computed from accepted family links at the moment the button is pressed.
 *
 * Worth being honest about what this does not do: a websocket only reaches
 * somebody with the app open. An SOS that arrives when a phone is in a pocket
 * needs a push notification, and that is not built yet. Until it is, this is
 * a fast alert to people already looking, plus a durable record in the
 * history — not a guarantee that anybody hears it.
 */
class SosRaised implements ShouldBroadcastNow
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
        return 'sos.raised';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $person = $this->alert->user;

        return [
            'alert' => app(SosService::class)->present($this->alert),

            /*
             | Who, inline.
             |
             | Everything the receiving phone needs to draw a full-screen
             | alarm without a round trip — because the round trip is exactly
             | what fails when something has gone wrong with the network, and
             | "someone in your family needs help" must never render as a
             | blank card with a uuid on it.
             */
            'person' => [
                'id' => $person?->uuid,
                'name' => $person?->name,
                'avatar_url' => $person?->avatar_url,
            ],
        ];
    }
}
