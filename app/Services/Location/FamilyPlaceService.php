<?php

namespace App\Services\Location;

use App\Events\Location\PlaceCrossed;
use App\Models\FamilyMember;
use App\Models\FamilyPlace;
use App\Models\PlaceVisit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Named circles, and who is inside them.
 *
 * Named `FamilyPlaceService`, not `PlaceService`, because
 * `App\Services\Safety\PlacesService` already exists and is a completely
 * different thing — the Google Places proxy behind the SOS screen's "nearest
 * hospital" search. Two classes one letter apart with unrelated jobs is a
 * mistake somebody makes at four in the afternoon.
 *
 * Two jobs that look like one and are not:
 *
 *  1. **Labels.** "Aarav — At School", read on every map refresh. Cheap,
 *     stateless from the caller's point of view, and derived from the open
 *     visit rows rather than recomputed from coordinates — which is what
 *     gives the label the same hysteresis the notifications have, for free.
 *  2. **Edges.** "Aarav arrived at School", evaluated once per accepted
 *     ping. Stateful by necessity, because an edge is a comparison with a
 *     moment ago.
 *
 * Keeping them on one table is what stops the label and the notification ever
 * disagreeing — which they would, immediately, if the label were a fresh
 * distance test and the notification came from stored state.
 */
class FamilyPlaceService
{
    /**
     * Places tested against one position, at most.
     *
     * A ceiling rather than a paging scheme: this runs inside a ping, and a
     * ping that takes longer as somebody's family grows is a ping that will
     * eventually time out. Twenty-five per person times a family of ten is
     * already generous; past that, the tail is silently not evaluated, which
     * is a better failure than a slow one.
     */
    public const MAX_PLACES_PER_PING = 120;

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * One viewer's own places.
     *
     * @return Collection<int, FamilyPlace>
     */
    public function forOwner(User $owner): Collection
    {
        return FamilyPlace::query()
            ->where('user_id', $owner->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * Where each of these people currently is, as far as the viewer's own
     * places are concerned.
     *
     * Returns `[user_id => FamilyPlace]` for everybody who is confirmed
     * inside one. The viewer's places only — a label is an answer to *their*
     * question, and "home" means the home they told us about.
     *
     * One query for the lot rather than one per member. This is called once
     * per map refresh with the whole roster, and N+1 here would be N+1 on the
     * hottest read in the feature.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, FamilyPlace>
     */
    public function currentPlaces(User $viewer, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $visits = PlaceVisit::query()
            ->open()
            ->whereNotNull('confirmed_at')
            ->whereIn('user_id', $userIds)
            ->whereIn(
                'place_id',
                FamilyPlace::query()->where('user_id', $viewer->id)->select('id'),
            )
            ->with('place')
            ->orderByDesc('entered_at')
            ->get();

        $out = [];

        foreach ($visits as $visit) {
            /*
             | Overlapping places are possible — a school inside a
             | neighbourhood circle — and somebody can legitimately be inside
             | both. The most recently entered wins, which is almost always
             | the smaller and more specific one, because you cross into the
             | big circle before the small one inside it.
             */
            if (isset($out[$visit->user_id])) {
                continue;
            }

            if ($visit->place !== null) {
                $out[$visit->user_id] = $visit->place;
            }
        }

        return $out;
    }

    /*
    |--------------------------------------------------------------------------
    | Writing
    |--------------------------------------------------------------------------
    */

    /**
     * Test one accepted position against every place that could care.
     *
     * Called from `LocationService::ping` after the fix has survived the
     * filters — never before. Feeding an unfiltered fix in here is how you
     * get "arrived at School" from a cell-tower reading two kilometres away.
     *
     * Failures are logged and swallowed. A geofence is a convenience; a
     * position write is the product. This must never be the reason a ping
     * fails.
     */
    public function evaluate(User $user, float $lat, float $lng): void
    {
        try {
            $this->cross($user, $lat, $lng);
        } catch (\Throwable $e) {
            Log::warning('Geofence evaluation failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function cross(User $user, float $lat, float $lng): void
    {
        $places = $this->relevantTo($user);

        if ($places->isEmpty()) {
            return;
        }

        /** @var array<int, PlaceVisit> $open */
        $open = PlaceVisit::query()
            ->open()
            ->where('user_id', $user->id)
            ->whereIn('place_id', $places->pluck('id'))
            ->get()
            ->keyBy('place_id')
            ->all();

        foreach ($places as $place) {
            $metres = $this->metresBetween(
                $lat,
                $lng,
                (float) $place->latitude,
                (float) $place->longitude,
            );

            $visit = $open[$place->id] ?? null;

            if ($visit === null) {
                if ($metres <= $place->radius_m) {
                    $this->enter($user, $place);
                }

                continue;
            }

            // Wider than the radius they came in through. See FamilyPlace.
            if ($metres > $place->exitRadius()) {
                $this->leave($user, $place, $visit);

                continue;
            }

            $this->confirm($user, $place, $visit);
        }
    }

    /**
     * Places whose owner can see this person.
     *
     * Their own, plus every place belonging to somebody in their accepted
     * family. Anything else would be a stranger's circle silently tracking
     * them, which is the thing this whole model exists to prevent.
     *
     * @return Collection<int, FamilyPlace>
     */
    private function relevantTo(User $user): Collection
    {
        $links = FamilyMember::query()
            ->accepted()
            ->involving($user->id)
            ->get(['owner_id', 'member_id']);

        $owners = $links
            ->flatMap(fn (FamilyMember $link) => [$link->owner_id, $link->member_id])
            ->push($user->id)
            ->unique()
            ->values()
            ->all();

        return FamilyPlace::query()
            ->whereIn('user_id', $owners)
            ->limit(self::MAX_PLACES_PER_PING)
            ->get();
    }

    /**
     * Crossed in. Recorded, but not announced and not yet labelled.
     *
     * Nothing is broadcast here — see [PlaceVisit::DWELL_SECONDS]. Somebody
     * driving past the school gets this row and then [leave] a few seconds
     * later, and nobody's phone ever buzzes, because they did not arrive
     * anywhere.
     */
    private function enter(User $user, FamilyPlace $place): void
    {
        $visit = new PlaceVisit(['entered_at' => now()]);

        // Not Fillable, and mass assignment on this codebase fails silently
        // rather than loudly. Assigned, not passed.
        $visit->place_id = $place->id;
        $visit->user_id = $user->id;

        $visit->save();
    }

    /** Still inside. Promote the visit once the dwell period has passed. */
    private function confirm(User $user, FamilyPlace $place, PlaceVisit $visit): void
    {
        if ($visit->isConfirmed()) {
            return;
        }

        if ($visit->entered_at->diffInSeconds(now(), true) < PlaceVisit::DWELL_SECONDS) {
            return;
        }

        $visit->confirmed_at = now();
        $visit->save();

        if ($place->notify_on_arrive) {
            $this->announce($place, $user, PlaceCrossed::ARRIVED);
        }
    }

    /**
     * Crossed out.
     *
     * An unconfirmed visit closes silently. It was a pass-through, and a
     * "left School" for somewhere they were never announced as arriving would
     * be the more confusing half of a pair of notifications.
     */
    private function leave(User $user, FamilyPlace $place, PlaceVisit $visit): void
    {
        $wasConfirmed = $visit->isConfirmed();

        $visit->left_at = now();
        $visit->save();

        if ($wasConfirmed && $place->notify_on_leave) {
            $this->announce($place, $user, PlaceCrossed::LEFT);
        }
    }

    /**
     * Tell the owner.
     *
     * Wrapped, like every other broadcast in this codebase: a dead Reverb
     * must degrade the feature, never fail the write that triggered it.
     */
    private function announce(FamilyPlace $place, User $who, string $direction): void
    {
        try {
            PlaceCrossed::dispatch($place, $who, $direction);
        } catch (\Throwable $e) {
            Log::warning('Place broadcast failed', [
                'place_id' => $place->id,
                'message' => $e->getMessage(),
            ]);
        }

        /*
         | The seam for push.
         |
         | This is where an arrival should reach a phone that is not running,
         | and today it does not — the broadcast above only lands on a family
         | member with the app open. Marked rather than left implicit, because
         | it is the same gap SOS has and it should be closed once, for both.
         */
    }

    /*
    |--------------------------------------------------------------------------
    | Editing
    |--------------------------------------------------------------------------
    */

    /**
     * Create or update one of my places.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(User $owner, ?string $uuid, array $input): FamilyPlace
    {
        $place = $uuid === null
            ? new FamilyPlace()
            : FamilyPlace::where('uuid', $uuid)->where('user_id', $owner->id)->first();

        abort_if($place === null, 404, 'That place no longer exists.');

        if ($uuid === null) {
            abort_if(
                FamilyPlace::where('user_id', $owner->id)->count() >= FamilyPlace::MAX_PER_USER,
                422,
                'You have reached the maximum number of places.',
            );
        }

        $moved = $uuid !== null && (
            abs((float) $place->latitude - (float) $input['latitude']) > 0.00001
            || abs((float) $place->longitude - (float) $input['longitude']) > 0.00001
            || (int) $place->radius_m !== (int) $input['radius_m']
        );

        $place->fill([
            'name' => $input['name'],
            'kind' => $input['kind'] ?? 'custom',
            'latitude' => $input['latitude'],
            'longitude' => $input['longitude'],
            'radius_m' => $input['radius_m'],
            'notify_on_arrive' => $input['notify_on_arrive'] ?? true,
            'notify_on_leave' => $input['notify_on_leave'] ?? true,
        ]);

        $place->user_id = $owner->id;
        $place->save();

        /*
         | Moving a circle invalidates who is standing in it.
         |
         | Without this, dragging "Home" across town leaves everybody who was
         | inside the old position marked as still being there — permanently,
         | because the leave test runs against the new coordinates and they
         | are nowhere near them, so it fires once and then they are simply
         | never inside anything again.
         |
         | Closing the open visits is the honest reset: the next ping from
         | anybody genuinely inside the new circle re-enters it, dwells, and
         | is announced properly.
         */
        if ($moved) {
            PlaceVisit::query()
                ->open()
                ->where('place_id', $place->id)
                ->update(['left_at' => now()]);
        }

        return $place;
    }

    public function delete(User $owner, string $uuid): void
    {
        $place = FamilyPlace::where('uuid', $uuid)
            ->where('user_id', $owner->id)
            ->first();

        abort_if($place === null, 404, 'That place no longer exists.');

        // Visits cascade with it. They are only meaningful as "time spent at
        // this place", and the place is going.
        $place->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    public function present(FamilyPlace $place): array
    {
        return [
            'id' => $place->uuid,
            'name' => $place->name,
            'kind' => $place->kind,
            'latitude' => (float) $place->latitude,
            'longitude' => (float) $place->longitude,
            'radius_m' => (int) $place->radius_m,
            'notify_on_arrive' => (bool) $place->notify_on_arrive,
            'notify_on_leave' => (bool) $place->notify_on_leave,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Geometry
    |--------------------------------------------------------------------------
    */

    /**
     * Great-circle distance in metres.
     *
     * The same haversine as LocationService rather than a shared helper: two
     * copies of eight lines of arithmetic that will never change is cheaper
     * than a geometry utility class that two services have to agree about.
     */
    private function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
