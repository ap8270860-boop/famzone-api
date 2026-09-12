<?php

namespace App\Events\Location;

use App\Models\FamilyPlace;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody arrived at, or left, one of your places.
 *
 * One event with a direction rather than two classes. Arriving and leaving
 * carry identical payloads, are authorised the same way, are rendered by the
 * same banner and are muted by the same pair of switches on the place — the
 * only thing that differs is a verb, and a verb is a field.
 *
 * ## Who receives it
 *
 * The place's owner, and only the owner. A place belongs to the person who
 * created it (see the family_places migration), so an arrival at "Home" is an
 * arrival at *their* home. Fanning it out to the wider family would mean
 * telling people about a landmark they have never heard of.
 *
 * The owner is told about themselves too, which is deliberate: it is the
 * fastest way for somebody to check their own geofence actually works, and
 * arriving home to a quiet phone is not a notification anybody minds.
 *
 * ## Queued
 *
 * Unlike `location.updated`, which is synchronous because a stale position is
 * worse than a late one. This fires once per boundary crossing after a
 * minute's dwell — it is already a minute old by definition, and a second on
 * a queue changes nothing.
 */
class PlaceCrossed implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public const ARRIVED = 'arrived';
    public const LEFT = 'left';

    public function __construct(
        public readonly FamilyPlace $place,
        public readonly User $who,
        public readonly string $direction,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $owner = $this->place->user ?? $this->place->user()->first();

        if ($owner === null) {
            return [];
        }

        return [new PrivateChannel('user.'.$owner->uuid)];
    }

    public function broadcastAs(): string
    {
        return 'place.crossed';
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
            'direction' => $this->direction,
            'at' => now()->toIso8601String(),
            'place' => [
                'id' => $this->place->uuid,
                'name' => $this->place->name,
                'kind' => $this->place->kind,
            ],
            'user' => [
                'id' => $this->who->uuid,
                'name' => $this->who->name,
                'avatar_url' => $this->who->avatar_url,
            ],

            /*
             | The sentence, written on the server.
             |
             | The client could assemble "Aarav arrived at School" from the
             | three fields above, and then so could the web app, and the two
             | would drift. More to the point, this string is what a push
             | notification will carry once push exists — and a push payload
             | is built where there is no client to ask.
             */
            'message' => sprintf(
                '%s %s %s',
                $this->who->name,
                $this->direction === self::ARRIVED ? 'arrived at' : 'left',
                $this->place->name,
            ),
        ];
    }
}
