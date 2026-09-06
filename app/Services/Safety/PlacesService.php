<?php

namespace App\Services\Safety;

use App\Models\PlaceLookup;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Places, from the server, cached.
 *
 * ---------------------------------------------------------------------------
 * Why this is not in the app
 * ---------------------------------------------------------------------------
 *
 * Three reasons, in order of how much they cost if you get them wrong.
 *
 *  1. The key. Android and iOS application restrictions only cover the Maps
 *     *SDKs*; a REST call to Places from Dart needs a key with no application
 *     restriction at all, and an unrestricted billable key inside a shipped
 *     APK is how people wake up to a bill. Here it lives on EC2, restricted
 *     to one IP address, and never enters a build.
 *
 *  2. Caching. A client cannot share an answer with the next user. This can:
 *     the hospitals near a given street corner are the same for everybody who
 *     stands there, so one paid call serves a whole neighbourhood for a week.
 *     That is the difference between a bill that scales with users and one
 *     that scales with places.
 *
 *  3. Tiers. Nearby Search bills at Pro, but asking for a phone number pulls
 *     the whole request up to Enterprise — seven times scarcer under India
 *     pricing. Keeping the two calls separate, and only fetching a number
 *     when somebody actually taps Call, is a decision that belongs somewhere
 *     it can be enforced rather than in a widget.
 *
 * ---------------------------------------------------------------------------
 * Failure is not an error
 * ---------------------------------------------------------------------------
 *
 * Every method here degrades to an empty list. No key configured, quota
 * exhausted, Google down, phone on a train with no signal — the answer is
 * "no places found", never an exception. The emergency *numbers* come from
 * EmergencyDirectory and never touch this class, so a person can always dial
 * 112 even when nothing else in the screen works. That separation is the most
 * important thing in this file.
 */
class PlacesService
{
    private const BASE = 'https://places.googleapis.com/v1';

    /** Metres. Roughly a ten-minute drive in a city. */
    public const DEFAULT_RADIUS = 5000;

    public const MAX_RADIUS = 20000;

    public const MAX_RESULTS = 20;

    /**
     * How coarse the cache grid is.
     *
     * Two decimal places is a little over a kilometre. Anyone within the same
     * cell gets the same answer, which is correct — "the nearest hospitals"
     * does not meaningfully differ across a hundred metres, and the exact
     * distance to each one is recomputed per request from the caller's real
     * position anyway.
     */
    private const GRID = 2;

    /** Places do not move. */
    private const NEARBY_TTL_DAYS = 7;

    /** Phone numbers move even less, and cost the most to fetch. */
    private const CONTACT_TTL_DAYS = 30;

    /** A typed query is more varied, so it earns a shorter life. */
    private const SEARCH_TTL_DAYS = 1;

    /*
    |--------------------------------------------------------------------------
    | Field masks
    |--------------------------------------------------------------------------
    |
    | These decide the bill. Places (New) charges by the most expensive field
    | in the mask, so one careless addition here moves every search in the app
    | from Pro to Enterprise. Do not add a phone number to the search mask.
    |
    */

    /** Essentials + Pro. Everything a list needs and nothing that costs more. */
    private const SEARCH_FIELDS =
        'places.id,places.displayName,places.formattedAddress,'
        .'places.location,places.primaryTypeDisplayName,places.businessStatus';

    /** Enterprise, because of the phone numbers. One place at a time, on tap. */
    private const CONTACT_FIELDS =
        'id,displayName,formattedAddress,location,'
        .'nationalPhoneNumber,internationalPhoneNumber,googleMapsUri';

    /**
     * Why the last lookup came back empty, if it did.
     *
     * Every method here degrades to an empty list, which is right — Google
     * being unreachable must never stop somebody dialling 112. But an empty
     * list with no explanation makes four completely different problems look
     * identical: no key, key refused, API not enabled, and genuinely nothing
     * nearby. Those have four different fixes, and the screen should say
     * which one it is rather than shrugging.
     */
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
     * The unconfigured case, phrased for whoever has to fix it.
     */
    private function notConfigured(): void
    {
        $this->failure = 'Nearby search is not set up on the server yet '
            .'(GOOGLE_PLACES_KEY is empty).';
    }

    /*
    |--------------------------------------------------------------------------
    | Nearby
    |--------------------------------------------------------------------------
    */

    /**
     * Places of a given kind, nearest first.
     *
     * @param  array<int, string>  $types
     * @return array<int, array<string, mixed>>
     */
    public function nearby(
        array $types,
        float $lat,
        float $lng,
        int $radius = self::DEFAULT_RADIUS,
    ): array {
        if ($types === [] || ! $this->configured()) {
            if (! $this->configured()) $this->notConfigured();

            return [];
        }

        sort($types);

        $radius = max(500, min($radius, self::MAX_RADIUS));

        $key = sprintf(
            'nearby:%s:%s:%s:%d',
            implode('+', $types),
            number_format($lat, self::GRID, '.', ''),
            number_format($lng, self::GRID, '.', ''),
            $radius,
        );

        $payload = PlaceLookup::fresh_($key);

        if ($payload === null) {
            $payload = $this->fetchNearby($types, $lat, $lng, $radius);

            if ($payload === null) {
                return [];
            }

            PlaceLookup::put($key, $payload, self::NEARBY_TTL_DAYS);
        }

        return $this->decorate($payload['places'] ?? [], $lat, $lng);
    }

    /**
     * @param  array<int, string>  $types
     * @return array<string, mixed>|null
     */
    private function fetchNearby(array $types, float $lat, float $lng, int $radius): ?array
    {
        return $this->post('/places:searchNearby', self::SEARCH_FIELDS, [
            'includedTypes' => $types,
            'maxResultCount' => self::MAX_RESULTS,

            /*
             | Nearest first, from Google, rather than by its own relevance
             | score. In an emergency "closest" is the only ranking that
             | means anything — a better-reviewed hospital twelve kilometres
             | away is the wrong answer.
             */
            'rankPreference' => 'DISTANCE',

            'locationRestriction' => [
                'circle' => [
                    'center' => ['latitude' => $lat, 'longitude' => $lng],
                    'radius' => (float) $radius,
                ],
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    /**
     * A typed query — "apollo hospital", "sector 62 police".
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, ?float $lat = null, ?float $lng = null): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2 || ! $this->configured()) {
            if (! $this->configured()) $this->notConfigured();

            return [];
        }

        $key = sprintf(
            'search:%s:%s:%s',
            mb_strtolower($query),
            $lat === null ? '-' : number_format($lat, self::GRID, '.', ''),
            $lng === null ? '-' : number_format($lng, self::GRID, '.', ''),
        );

        // Long queries would overflow the 191-character unique index, and a
        // hash is as good a key as the text it stands for.
        if (mb_strlen($key) > 180) {
            $key = 'search:'.md5($key);
        }

        $payload = PlaceLookup::fresh_($key);

        if ($payload === null) {
            $body = ['textQuery' => $query, 'maxResultCount' => self::MAX_RESULTS];

            /*
             | Biased towards the caller, not restricted to them.
             |
             | locationBias rather than locationRestriction: somebody typing
             | "AIIMS" wants the one near them first, but should still find
             | the one they actually meant if it is further away.
             */
            if ($lat !== null && $lng !== null) {
                $body['locationBias'] = [
                    'circle' => [
                        'center' => ['latitude' => $lat, 'longitude' => $lng],
                        'radius' => (float) self::MAX_RADIUS,
                    ],
                ];
            }

            $payload = $this->post('/places:searchText', self::SEARCH_FIELDS, $body);

            if ($payload === null) {
                return [];
            }

            PlaceLookup::put($key, $payload, self::SEARCH_TTL_DAYS);
        }

        return $this->decorate($payload['places'] ?? [], $lat, $lng);
    }

    /*
    |--------------------------------------------------------------------------
    | Contact
    |--------------------------------------------------------------------------
    */

    /**
     * One place's phone number.
     *
     * The expensive call, and the reason it is its own method: this is
     * Enterprise-tier billing, and it must only ever happen when a person has
     * actually tapped Call on one specific place. Requesting it for a list of
     * twenty would cost twenty Enterprise calls to answer a question nobody
     * asked.
     *
     * @return array<string, mixed>|null
     */
    public function contact(string $placeId): ?array
    {
        if ($placeId === '' || ! $this->configured()) {
            if (! $this->configured()) $this->notConfigured();

            return null;
        }

        $key = 'contact:'.$placeId;

        $payload = PlaceLookup::fresh_($key);

        if ($payload === null) {
            $payload = $this->get('/places/'.rawurlencode($placeId), self::CONTACT_FIELDS);

            if ($payload === null) {
                return null;
            }

            PlaceLookup::put($key, $payload, self::CONTACT_TTL_DAYS);
        }

        return [
            'place_id' => $payload['id'] ?? $placeId,
            'name' => $payload['displayName']['text'] ?? null,
            'address' => $payload['formattedAddress'] ?? null,
            'phone' => $payload['nationalPhoneNumber'] ?? null,
            'phone_international' => $payload['internationalPhoneNumber'] ?? null,
            'maps_url' => $payload['googleMapsUri'] ?? null,
            'latitude' => $payload['location']['latitude'] ?? null,
            'longitude' => $payload['location']['longitude'] ?? null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Shaping
    |--------------------------------------------------------------------------
    */

    /**
     * Google's shape into ours, with a distance added.
     *
     * Distance is computed here, per request, from the caller's real position
     * — never cached. The cached rows are keyed by a rounded grid cell, so a
     * stored distance would be up to a kilometre wrong for everybody who was
     * not standing exactly at the centre of it.
     *
     * @param  array<int, array<string, mixed>>  $places
     * @return array<int, array<string, mixed>>
     */
    private function decorate(array $places, ?float $lat, ?float $lng): array
    {
        $out = [];

        foreach ($places as $place) {
            $placeLat = $place['location']['latitude'] ?? null;
            $placeLng = $place['location']['longitude'] ?? null;

            $metres = ($lat !== null && $lng !== null && $placeLat !== null)
                ? $this->metresBetween($lat, $lng, (float) $placeLat, (float) $placeLng)
                : null;

            $out[] = [
                'place_id' => $place['id'] ?? null,
                'name' => $place['displayName']['text'] ?? 'Unnamed',
                'address' => $place['formattedAddress'] ?? null,
                'kind' => $place['primaryTypeDisplayName']['text'] ?? null,

                /*
                 | Google marks places it believes have shut down. Passed
                 | through rather than filtered out: a closed hospital that is
                 | still the nearest building matters, and hiding it silently
                 | would leave a gap nobody can explain.
                 */
                'operational' => ($place['businessStatus'] ?? 'OPERATIONAL') === 'OPERATIONAL',

                'latitude' => $placeLat === null ? null : (float) $placeLat,
                'longitude' => $placeLng === null ? null : (float) $placeLng,
                'distance_m' => $metres === null ? null : (int) round($metres),
                'distance_label' => $metres === null ? null : $this->distanceLabel($metres),
            ];
        }

        // Google's DISTANCE ranking is by straight line from the cell centre,
        // which is close but not ours. Re-sorted against the caller's own
        // position so the first row really is the nearest one to them.
        usort($out, function (array $a, array $b) {
            return ($a['distance_m'] ?? PHP_INT_MAX) <=> ($b['distance_m'] ?? PHP_INT_MAX);
        });

        return $out;
    }

    private function distanceLabel(float $metres): string
    {
        if ($metres < 950) {
            return round($metres / 50) * 50 .' m';
        }

        return number_format($metres / 1000, 1).' km';
    }

    private function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function post(string $path, string $fields, array $body): ?array
    {
        return $this->send(fn () => Http::withHeaders($this->headers($fields))
            ->timeout(8)
            ->post(self::BASE.$path, $body), $path);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function get(string $path, string $fields): ?array
    {
        return $this->send(fn () => Http::withHeaders($this->headers($fields))
            ->timeout(8)
            ->get(self::BASE.$path), $path);
    }

    /**
     * @return array<string, string>
     */
    /**
     * Google's rejection, in words that point at the fix.
     *
     * The three that actually happen in practice are a Places API that was
     * never enabled on the project, a key restricted to the wrong APIs, and
     * an exhausted quota. All three arrive as a 403 or 429 with the real
     * reason buried in a JSON body nobody reads, so it gets pulled out here.
     */
    private function explain(int $status, string $body): string
    {
        if (str_contains($body, 'SERVICE_DISABLED')
            || str_contains($body, 'has not been used in project')) {
            return 'The Places API (New) is not enabled for this Google Cloud '
                .'project. Enable it in APIs & Services, then try again.';
        }

        if (str_contains($body, 'API_KEY_HTTP_REFERRER_BLOCKED')
            || str_contains($body, 'API_KEY_IP_ADDRESS_BLOCKED')) {
            return 'Google refused this key from this server. Check the key’s '
                .'IP restriction matches the API server.';
        }

        if ($status === 403) {
            return 'Google refused the request. Check the key exists and its '
                .'API restrictions include Places API (New).';
        }

        if ($status === 429) {
            return 'Google Places quota is exhausted for now.';
        }

        return "Google Places returned $status.";
    }

    private function headers(string $fields): array
    {
        return [
            'X-Goog-Api-Key' => (string) config('services.google.places_key'),
            'X-Goog-FieldMask' => $fields,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @param  callable(): \Illuminate\Http\Client\Response  $call
     * @return array<string, mixed>|null
     */
    private function send(callable $call, string $path): ?array
    {
        try {
            $response = $call();
        } catch (\Throwable $e) {
            /*
             | Swallowed, and this is the point of the whole class.
             |
             | Google being unreachable must never stop somebody dialling 112.
             | The screen loses its list of nearby places and keeps every
             | number on it, which is the correct degradation for a safety
             | feature — logged loudly, because an outage nobody notices is an
             | outage nobody fixes.
             */
            Log::warning('places request failed', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('places request rejected', [
                'path' => $path,
                'status' => $response->status(),
                // Google puts the actual reason here — a wrong key, a
                // disabled API, an exhausted quota all look identical
                // without it.
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            $this->failure = $this->explain($response->status(), $response->body());

            return null;
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : null;
    }
}
