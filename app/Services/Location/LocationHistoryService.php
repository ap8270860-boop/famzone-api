<?php

namespace App\Services\Location;

use App\Models\FamilyPlace;
use App\Models\LocationPing;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One person's day, reconstructed.
 *
 * `location_pings` holds a few thousand coordinates per person per day, which
 * answers no question anybody has. "Where was Aarav yesterday" wants a
 * sentence — *at school until half three, then twenty minutes to the park,
 * home by five* — and this is what turns one into the other.
 *
 * ## Stays are found in the data, not in the places table
 *
 * The obvious implementation reads place_visits and calls the gaps journeys.
 * It is wrong for the case that matters most: a family that has not set any
 * places up yet, which is every family on their first day. It would show them
 * one undifferentiated twenty-four-hour journey.
 *
 * So stays are clustered out of the ping stream itself — a run of fixes that
 * stay within a small radius for long enough is a stay, wherever it is. If it
 * happens to fall inside one of the viewer's places, it gets that name; if it
 * does not, it is "Stopped" with coordinates. Places make the timeline
 * readable. They are not what makes it work.
 *
 * ## Distance is measured along the simplified line
 *
 * Summing haversine between consecutive fixes is the obvious way to total a
 * day and it overstates enormously. A simulated Tuesday — eight hours at home,
 * two short drives, six hours at school — totals 34 km that way against a true
 * 8 km. Ordinary GPS wander on a phone lying still is the whole difference.
 *
 * The obvious fix, ignoring steps below some floor, is worse: at a five-second
 * cadence a walking person moves seven metres per fix, so any floor high
 * enough to reject jitter rejects walking entirely. That version measured
 * every journey as zero, which is a far more confident kind of wrong.
 *
 * So the total is measured along the *simplified* polyline. Douglas–Peucker
 * already knows the difference between wobble and shape, and reusing it here
 * means one mechanism answering both "what line do we draw" and "how far did
 * they go". On the same simulated day it lands within 1% of the truth.
 */
class LocationHistoryService
{
    /** A cluster this tight, for this long, is somebody stopping. */
    public const STAY_RADIUS_M = 120;
    public const STAY_MINUTES = 8;

    /**
     * Tolerance used when measuring a journey, in metres.
     *
     * Tighter than the one used for drawing: the line on screen can afford to
     * lose a little shape, and a total cannot.
     */
    public const MEASURE_TOLERANCE_M = 5.0;

    /**
     * How far back history can be read.
     *
     * Shorter than the ping retention on purpose. Pings are kept for ninety
     * days because a safety app may need them; history is *browsable* for
     * thirty, because a calendar that scrolls back three months invites a
     * kind of routine surveillance that a family app should not be quietly
     * good at.
     */
    public const MAX_DAYS_BACK = 30;

    /** Points read for one day before the query gives up. */
    public const MAX_PINGS = 20000;

    /** Points kept per journey after simplification. */
    public const MAX_ROUTE_POINTS = 300;

    public function __construct(private readonly LocationService $locations)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | A day
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    public function day(
        User $viewer,
        string $sharerUuid,
        string $date,
        int $offsetMinutes,
    ): array {
        $sharer = $this->authorise($viewer, $sharerUuid);

        [$from, $to] = $this->window($date, $offsetMinutes);

        $pings = LocationPing::query()
            ->where('user_id', $sharer->id)
            ->where('recorded_at', '>=', $from)
            ->where('recorded_at', '<', $to)
            ->orderBy('recorded_at')
            ->limit(self::MAX_PINGS)
            ->get(['latitude', 'longitude', 'accuracy', 'speed', 'recorded_at']);

        $segments = $this->segment($pings);
        $places = $this->placesOf($viewer);

        $entries = [];
        $distance = 0.0;
        $movingSeconds = 0;
        $topSpeed = 0.0;
        $stays = 0;

        foreach ($segments as $segment) {
            if ($segment['kind'] === 'stay') {
                $stays++;

                $place = $this->placeAt(
                    $places,
                    $segment['latitude'],
                    $segment['longitude'],
                );

                $entries[] = [
                    'kind' => 'stay',
                    'from' => $segment['from']->toIso8601String(),
                    'to' => $segment['to']->toIso8601String(),
                    'seconds' => $segment['seconds'],
                    'latitude' => round($segment['latitude'], 6),
                    'longitude' => round($segment['longitude'], 6),
                    'place' => $place === null ? null : [
                        'id' => $place->uuid,
                        'name' => $place->name,
                        'kind' => $place->kind,
                    ],
                ];

                continue;
            }

            $distance += $segment['metres'];
            $movingSeconds += $segment['seconds'];
            $topSpeed = max($topSpeed, $segment['top_speed']);

            $entries[] = [
                'kind' => 'journey',
                'from' => $segment['from']->toIso8601String(),
                'to' => $segment['to']->toIso8601String(),
                'seconds' => $segment['seconds'],
                'metres' => (int) round($segment['metres']),
                'top_speed' => round($segment['top_speed'], 1),
                'route' => array_map(
                    fn (array $p) => [
                        'latitude' => round($p['lat'], 6),
                        'longitude' => round($p['lng'], 6),
                    ],
                    RouteSimplifier::fit($segment['points'], self::MAX_ROUTE_POINTS),
                ),
            ];
        }

        return [
            'user_id' => $sharer->uuid,
            'date' => $date,
            'entries' => $entries,
            'summary' => [
                'metres' => (int) round($distance),
                'moving_seconds' => $movingSeconds,
                'top_speed' => round($topSpeed, 1),
                'stays' => $stays,
                'points' => $pings->count(),

                /*
                 | Whether the day is complete, or whether we simply ran out
                 | of rows.
                 |
                 | A truncated day would otherwise look like somebody who
                 | stopped moving at lunchtime, which is exactly the wrong
                 | thing for a safety app to imply by accident.
                 */
                'truncated' => $pings->count() >= self::MAX_PINGS,
            ],
        ];
    }

    /**
     * Which days in a month have anything in them.
     *
     * For the calendar's dots. One grouped query rather than thirty day
     * queries — the calendar asks for a whole month at once and the honest
     * cost of that is one scan, not thirty.
     *
     * @return array<string, mixed>
     */
    public function days(
        User $viewer,
        string $sharerUuid,
        string $month,
        int $offsetMinutes,
    ): array {
        $sharer = $this->authorise($viewer, $sharerUuid);

        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00')
            ->subMinutes($offsetMinutes);

        $end = $start->addMonth();

        $floor = CarbonImmutable::now()->subDays(self::MAX_DAYS_BACK);

        /*
         | Bucketed in SQL, in the *viewer's* day.
         |
         | Shifting the timestamp by the offset before truncating is what makes
         | "Tuesday" mean Tuesday where the person is standing. Doing it in PHP
         | would mean pulling every ping of the month across to count them.
         */
        $rows = LocationPing::query()
            ->where('user_id', $sharer->id)
            ->where('recorded_at', '>=', $start->max($floor))
            ->where('recorded_at', '<', $end)
            ->selectRaw(
                'DATE(DATE_ADD(recorded_at, INTERVAL ? MINUTE)) as day, COUNT(*) as points',
                [$offsetMinutes],
            )
            ->groupBy('day')
            ->pluck('points', 'day');

        return [
            'user_id' => $sharer->uuid,
            'month' => $month,
            'oldest' => $floor->toDateString(),
            'days' => $rows->map(fn ($points) => (int) $points)->all(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Segmentation
    |--------------------------------------------------------------------------
    */

    /**
     * Split a stream of fixes into stays and journeys.
     *
     * One pass, greedy. A cluster starts at the first unassigned fix and
     * absorbs every following fix within [STAY_RADIUS_M] of its *anchor* —
     * not of the previous fix, which would let a slow walk drift across a
     * city one tolerated step at a time.
     *
     * @param  Collection<int, LocationPing>  $pings
     * @return array<int, array<string, mixed>>
     */
    private function segment(Collection $pings): array
    {
        $rows = $pings->values();
        $count = $rows->count();

        if ($count === 0) {
            return [];
        }

        $segments = [];

        /** @var array<int, LocationPing> $pending  fixes not yet in a stay */
        $pending = [];

        $i = 0;

        while ($i < $count) {
            $anchor = $rows[$i];

            $j = $i + 1;

            while ($j < $count && $this->metres(
                (float) $anchor->latitude,
                (float) $anchor->longitude,
                (float) $rows[$j]->latitude,
                (float) $rows[$j]->longitude,
            ) <= self::STAY_RADIUS_M) {
                $j++;
            }

            $span = $anchor->recorded_at->diffInSeconds($rows[$j - 1]->recorded_at, true);

            if ($j - $i >= 2 && $span >= self::STAY_MINUTES * 60) {
                // Everything queued before this becomes the journey that led
                // here.
                if ($pending !== []) {
                    $segments[] = $this->journey($pending, $anchor);
                    $pending = [];
                }

                $segments[] = $this->stay($rows->slice($i, $j - $i)->values()->all());

                $i = $j;

                continue;
            }

            // Not long enough to be a stay: these fixes are travel.
            $pending[] = $anchor;
            $i++;
        }

        if ($pending !== []) {
            $segments[] = $this->journey($pending, null);
        }

        return $this->mergeStays($segments);
    }

    /**
     * Two stays in a row are one stay.
     *
     * The greedy scan anchors each cluster on the first fix it sees, which
     * straight after a journey is a fix from the *approach* rather than from
     * the destination. Its circle is offset by most of a radius, so the far
     * edge of the real stay falls outside it and one stop is reported as two —
     * "at school for 12 minutes, then at school for 6 hours".
     *
     * Anchoring on a centroid instead would need a second pass anyway, and
     * would still split a stay that drifts. Joining afterwards is both simpler
     * and more robust: if two consecutive stays are in the same place, they
     * were the same stop.
     *
     * @param  array<int, array<string, mixed>>  $segments
     * @return array<int, array<string, mixed>>
     */
    private function mergeStays(array $segments): array
    {
        $out = [];

        foreach ($segments as $segment) {
            $previous = $out === [] ? null : $out[count($out) - 1];

            if (
                $segment['kind'] === 'stay'
                && $previous !== null
                && $previous['kind'] === 'stay'
                && $this->metres(
                    $previous['latitude'],
                    $previous['longitude'],
                    $segment['latitude'],
                    $segment['longitude'],
                ) <= self::STAY_RADIUS_M
            ) {
                // The joined centroid, weighted by how long each half lasted.
                // A twelve-minute approach should not pull the centre of a
                // six-hour stay towards the road.
                $weightA = max(1, $previous['seconds']);
                $weightB = max(1, $segment['seconds']);
                $sum = $weightA + $weightB;

                $out[count($out) - 1] = [
                    'kind' => 'stay',
                    'from' => $previous['from'],
                    'to' => $segment['to'],
                    'seconds' => (int) $previous['from']->diffInSeconds($segment['to'], true),
                    'latitude' => ($previous['latitude'] * $weightA
                        + $segment['latitude'] * $weightB) / $sum,
                    'longitude' => ($previous['longitude'] * $weightA
                        + $segment['longitude'] * $weightB) / $sum,
                ];

                continue;
            }

            $out[] = $segment;
        }

        return $out;
    }

    /**
     * @param  array<int, LocationPing>  $rows
     * @return array<string, mixed>
     */
    private function stay(array $rows): array
    {
        $first = $rows[0];
        $last = $rows[count($rows) - 1];

        // The centroid, not the first fix. A stay's anchor is wherever the
        // phone happened to be when it stopped moving, which for a house is
        // as likely to be the pavement outside as the sofa.
        $lat = 0.0;
        $lng = 0.0;

        foreach ($rows as $row) {
            $lat += (float) $row->latitude;
            $lng += (float) $row->longitude;
        }

        return [
            'kind' => 'stay',
            'from' => $first->recorded_at,
            'to' => $last->recorded_at,
            'seconds' => (int) $first->recorded_at->diffInSeconds($last->recorded_at, true),
            'latitude' => $lat / count($rows),
            'longitude' => $lng / count($rows),
        ];
    }

    /**
     * @param  array<int, LocationPing>  $rows
     * @return array<string, mixed>
     */
    private function journey(array $rows, ?LocationPing $arrival): array
    {
        /*
         | The arrival fix is stitched on to the end.
         |
         | Without it every journey line stops short of the place it arrived
         | at — a visible gap between the end of the route and the marker for
         | where somebody went, which looks like missing data rather than a
         | segmentation boundary.
         */
        if ($arrival !== null) {
            $rows[] = $arrival;
        }

        $points = [];
        $top = 0.0;

        foreach ($rows as $row) {
            $points[] = [
                'lat' => (float) $row->latitude,
                'lng' => (float) $row->longitude,
            ];

            if ($row->speed !== null) {
                $top = max($top, (float) $row->speed);
            }
        }

        // Along the simplified line, not along the raw fixes. See the class
        // note — this is the difference between 8 km and 34 km.
        $measured = RouteSimplifier::simplify($points, self::MEASURE_TOLERANCE_M);

        $metres = 0.0;

        for ($i = 1, $n = count($measured); $i < $n; $i++) {
            $metres += $this->metres(
                $measured[$i - 1]['lat'],
                $measured[$i - 1]['lng'],
                $measured[$i]['lat'],
                $measured[$i]['lng'],
            );
        }

        $first = $rows[0];
        $last = $rows[count($rows) - 1];

        return [
            'kind' => 'journey',
            'from' => $first->recorded_at,
            'to' => $last->recorded_at,
            'seconds' => (int) $first->recorded_at->diffInSeconds($last->recorded_at, true),
            'metres' => $metres,
            'top_speed' => $top,
            'points' => $points,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Places
    |--------------------------------------------------------------------------
    */

    /**
     * @return Collection<int, FamilyPlace>
     */
    private function placesOf(User $viewer): Collection
    {
        return FamilyPlace::query()->where('user_id', $viewer->id)->get();
    }

    /**
     * Which of the viewer's places, if any, a stay falls inside.
     *
     * Recomputed from the centroid rather than read from place_visits.
     * Deliberate: a visit row records what was true when the phone was there,
     * and a place moved or resized since would leave the timeline describing
     * a circle that no longer exists. The timeline should describe the world
     * as it is now, because that is the world the reader is in.
     *
     * @param  Collection<int, FamilyPlace>  $places
     */
    private function placeAt(Collection $places, float $lat, float $lng): ?FamilyPlace
    {
        $best = null;
        $bestRadius = PHP_FLOAT_MAX;

        foreach ($places as $place) {
            $distance = $this->metres(
                $lat,
                $lng,
                (float) $place->latitude,
                (float) $place->longitude,
            );

            // The smallest circle containing the point wins — a school inside
            // a neighbourhood circle should read as the school.
            if ($distance <= $place->radius_m && $place->radius_m < $bestRadius) {
                $best = $place;
                $bestRadius = $place->radius_m;
            }
        }

        return $best;
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    /**
     * The same permission as the live map, asked again.
     *
     * History is not a weaker claim than the present — it is a stronger one,
     * because it is a record rather than a moment. So somebody who has
     * stopped sharing takes their past with them: no live share, no history.
     *
     * That does cost the "where was he yesterday" case for a share that has
     * since ended, and it is the right cost. A family share has no expiry, so
     * in practice it survives; a fifteen-minute share in a chat thread does
     * not, which is exactly what somebody agreeing to fifteen minutes meant.
     */
    private function authorise(User $viewer, string $sharerUuid): User
    {
        $sharer = User::where('uuid', $sharerUuid)->first();

        abort_if($sharer === null, 404, 'That account no longer exists.');

        abort_unless(
            $sharer->id === $viewer->id || $this->locations->canView($viewer, $sharer),
            403,
            'You cannot see that location history.',
        );

        return $sharer;
    }

    /**
     * A local calendar day, as a pair of UTC instants.
     *
     * The offset arrives from the phone rather than from a stored timezone,
     * because the question is "what did Tuesday look like where I am now" —
     * and somebody reading this on a plane is asking about the day they are
     * living in, not the one their profile was set up in.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(string $date, int $offsetMinutes): array
    {
        $localMidnight = CarbonImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $date.' 00:00:00',
        );

        $from = $localMidnight->subMinutes($offsetMinutes);

        abort_if(
            $from->lessThan(CarbonImmutable::now()->subDays(self::MAX_DAYS_BACK + 1)),
            422,
            'History goes back '.self::MAX_DAYS_BACK.' days.',
        );

        return [$from, $from->addDay()];
    }

    /** Great-circle distance in metres. */
    private function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
