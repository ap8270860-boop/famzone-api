<?php

namespace App\Services\Location;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One route, from here to there.
 *
 * A thin proxy over Google's Routes API, and thin on purpose: the app needs a
 * line to draw, a distance, and a time. Everything else the API can return —
 * lane guidance, toll estimates, step-by-step manoeuvres — is deliberately not
 * asked for, because the field mask is what decides the bill.
 *
 * **The key never leaves this server.** Same rule as PlacesService, for the
 * same reason: a key in an APK is a key anybody can unzip out of it, and an
 * IP restriction is only a restriction while the caller is this box.
 *
 * **Caching is short, not clever.** A route is traffic-aware, so yesterday's
 * answer is worse than useless. Sixty seconds is enough to stop a double-tap
 * being billed twice and short enough that the ETA still means something.
 */
class RoutingService
{
    private const ENDPOINT =
        'https://routes.googleapis.com/directions/v2:computeRoutes';

    /**
     * The whole bill, in one line.
     *
     * Asking only for these three keeps every request on Routes: Compute
     * Routes Basic. Adding `routes.legs.steps` moves it to Advanced and
     * multiplies the cost — which is the trade made when turn-by-turn was
     * scoped out, and is why this comment is here rather than in a ticket.
     */
    private const FIELDS =
        'routes.duration,routes.distanceMeters,routes.polyline.encodedPolyline';

    /** Traffic moves. A route is worth very little the moment it is stale. */
    private const CACHE_SECONDS = 60;

    /**
     * Cache grid, in decimal places.
     *
     * Four places is about eleven metres — tight enough that two genuinely
     * different journeys never share an answer, loose enough that a jittering
     * GPS fix does not bill a fresh route every second while somebody stands
     * at the start of one.
     */
    private const GRID = 4;

    /** Beyond this, a route is not what somebody wanted. */
    public const MAX_METRES = 500000;

    private ?string $failure = null;

    public function failure(): ?string
    {
        return $this->failure;
    }

    public function configured(): bool
    {
        return ! empty(config('services.google.places_key'));
    }

    /**
     * Drive, walk, cycle or two-wheeler.
     *
     * TWO_WHEELER is included because in India it is the honest default for a
     * great many journeys, and routing a scooter as a car sends it down roads
     * it should not be on.
     *
     * @var list<string>
     */
    public const MODES = ['DRIVE', 'TWO_WHEELER', 'WALK', 'BICYCLE'];

    /**
     * @return array<string, mixed>|null
     */
    public function route(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        string $mode = 'DRIVE',
    ): ?array {
        $this->failure = null;

        if (! $this->configured()) {
            $this->failure = 'Routing is not set up on the server yet '
                .'(GOOGLE_PLACES_KEY is empty).';

            return null;
        }

        $mode = in_array($mode, self::MODES, true) ? $mode : 'DRIVE';

        $key = sprintf(
            'route.v1.%s.%s,%s.%s,%s',
            strtolower($mode),
            round($fromLat, self::GRID),
            round($fromLng, self::GRID),
            round($toLat, self::GRID),
            round($toLng, self::GRID),
        );

        $cached = cache()->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'X-Goog-Api-Key' => config('services.google.places_key'),
                    'X-Goog-FieldMask' => self::FIELDS,
                ])
                ->post(self::ENDPOINT, $this->payload($fromLat, $fromLng, $toLat, $toLng, $mode));

            if ($response->failed()) {
                $this->explain($response->status(), $response->json());

                return null;
            }

            $route = $response->json('routes.0');

            if (! is_array($route)) {
                $this->failure = 'No route between those two points.';

                return null;
            }

            $out = [
                // Google returns "1234s". Seconds, as an integer, is what a
                // countdown needs.
                'duration_seconds' => (int) rtrim((string) ($route['duration'] ?? '0'), 's'),
                'distance_metres' => (int) ($route['distanceMeters'] ?? 0),
                'polyline' => $route['polyline']['encodedPolyline'] ?? '',
                'mode' => $mode,
            ];

            if ($out['polyline'] === '') {
                $this->failure = 'The route came back without a line to draw.';

                return null;
            }

            cache()->put($key, $out, self::CACHE_SECONDS);

            return $out;
        } catch (\Throwable $e) {
            Log::warning('routing failed', ['error' => $e->getMessage()]);

            $this->failure = 'Could not reach the routing service.';

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        string $mode,
    ): array {
        $body = [
            'origin' => $this->waypoint($fromLat, $fromLng),
            'destination' => $this->waypoint($toLat, $toLng),
            'travelMode' => $mode,
            'polylineQuality' => 'OVERVIEW',
            'languageCode' => 'en-IN',
            'units' => 'METRIC',
        ];

        /*
         | Traffic-aware only where it is allowed to be.
         |
         | Omitted rather than sent as null: Google rejects the field outright
         | on WALK and BICYCLE, and a null is still the field being present.
         |
         | TRAFFIC_AWARE and not TRAFFIC_AWARE_OPTIMAL — the optimal one costs
         | more and is built for planning many routes at once, where this is
         | one person who wants to leave now.
         */
        if (in_array($mode, ['DRIVE', 'TWO_WHEELER'], true)) {
            $body['routingPreference'] = 'TRAFFIC_AWARE';
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function waypoint(float $lat, float $lng): array
    {
        return [
            'location' => [
                'latLng' => ['latitude' => $lat, 'longitude' => $lng],
            ],
        ];
    }

    /**
     * Four different problems that otherwise look identical.
     *
     * The same reasoning as PlacesService::$failure — "no route" with no
     * explanation sends somebody looking at their own code when the actual
     * answer is that the Routes API was never enabled on the key.
     *
     * @param  mixed  $body
     */
    private function explain(int $status, $body): void
    {
        $message = is_array($body)
            ? (string) ($body['error']['message'] ?? '')
            : '';

        $this->failure = match (true) {
            $status === 403 && str_contains($message, 'has not been used')
                => 'The Routes API is not enabled on this key. Enable it in '
                    .'Google Cloud Console, then try again.',

            $status === 403
                => 'Google refused the key for routing. Check its API '
                    .'restrictions include Routes API.',

            $status === 400
                => 'Google rejected the route request: '.$message,

            $status === 429
                => 'Too many route requests. Try again shortly.',

            default => 'Routing failed ('.$status.').',
        };

        Log::warning('routing rejected', [
            'status' => $status,
            'message' => $message,
        ]);
    }
}
