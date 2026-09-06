"""
Wire the SOS feature into the files that already exist.

Guarded and idempotent, like the others.

    python apply_sos.py --dry-run
    python apply_sos.py
"""

from __future__ import annotations

import argparse
import pathlib
import sys

ROOT = pathlib.Path(__file__).resolve().parent

pending: dict[pathlib.Path, str] = {}
applied: list[str] = []
skipped: list[str] = []
failures: list[str] = []


def read(path: pathlib.Path) -> str:
    return pending.get(path) or path.read_text(encoding="utf-8")


def patch(rel: str, label: str, anchor: str, replacement: str,
          *, expect: int = 1, done_marker: str) -> None:
    path = ROOT / rel

    if not path.exists():
        failures.append(f"{label}: {rel} not found")
        return

    text = read(path)

    if done_marker in text:
        skipped.append(f"{label} (already applied)")
        return

    found = text.count(anchor)

    if found != expect:
        failures.append(f"{label}: anchor found {found}x in {rel}, expected {expect}")
        return

    pending[path] = text.replace(anchor, replacement, expect)
    applied.append(f"{label}  ->  {rel}")


def append_env(rel: str, label: str, block: str, done_marker: str) -> None:
    path = ROOT / rel

    if not path.exists():
        skipped.append(f"{label} ({rel} absent)")
        return

    text = read(path)

    if done_marker in text:
        skipped.append(f"{label} (already applied)")
        return

    pending[path] = text.rstrip("\n") + "\n" + block
    applied.append(f"{label}  ->  {rel}")


# --------------------------------------------------------------- config

patch(
    "config/services.php",
    "config: google places key",
    """    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],""",
    """    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google
    |--------------------------------------------------------------------------
    |
    | The Places key, and only the Places key. This is a different credential
    | from the Maps SDK keys in the mobile app: those are restricted by
    | package name and signing fingerprint and are expected to ship inside
    | the binary, whereas this one is restricted by IP to this server and must
    | never leave it.
    |
    | Empty is a supported state. PlacesService checks before every call and
    | degrades to "no nearby places found" — the emergency numbers come from
    | EmergencyDirectory and never touch Google, so an unconfigured or
    | exhausted key can never stop somebody dialling 112.
    |
    */
    'google' => [
        'places_key' => env('GOOGLE_PLACES_KEY'),
    ],""",
    done_marker="'places_key' => env('GOOGLE_PLACES_KEY')",
)

for env_file in (".env", ".env.example"):
    append_env(
        env_file,
        f"env: GOOGLE_PLACES_KEY ({env_file})",
        "\n# Google Places (New), for nearest-hospital / police search on the SOS\n"
        "# screen. Server-side only: restrict this key by IP to this machine and\n"
        "# to the Places API. Leave it empty and the SOS screen still works —\n"
        "# it simply offers phone numbers without a list of nearby places.\n"
        "GOOGLE_PLACES_KEY=\n",
        done_marker="GOOGLE_PLACES_KEY",
    )


# --------------------------------------------------------------- routes

patch(
    "routes/api.php",
    "routes: sos group",
    """            Route::get('check-ins', [V1Controller::class, 'checkInHistory'])
                ->name('check-ins');
        });""",
    """            Route::get('check-ins', [V1Controller::class, 'checkInHistory'])
                ->name('check-ins');

            /*
             | SOS.
             |
             | `sos` is the screen, cold: the catalogue of services and
             | whatever alert is already running. Polled on every open of the
             | tab, so it gets the standard read limit.
             |
             | Raising and ending are throttled far tighter — not to protect
             | the server, which barely notices them, but because each one
             | reaches every member of somebody's family. A loop here is a
             | family being woken up repeatedly, and that is the expensive
             | failure.
             |
             | `nearby` and `contact` are the only endpoints in the API that
             | cost money per call. `contact` bills at Google's Enterprise
             | tier and is therefore the tightest of the lot — it should only
             | ever fire when a person has tapped Call on one specific place.
             */
            Route::get('sos', [V1Controller::class, 'sosOverview'])
                ->middleware('throttle:60,1')
                ->name('sos.overview');

            Route::post('sos', [V1Controller::class, 'startSos'])
                ->middleware('throttle:10,1')
                ->name('sos.start');

            Route::get('sos/history', [V1Controller::class, 'sosHistory'])
                ->middleware('throttle:60,1')
                ->name('sos.history');

            Route::get('sos/nearby', [V1Controller::class, 'sosNearby'])
                ->middleware('throttle:40,1')
                ->name('sos.nearby');

            Route::get('sos/places/{placeId}', [V1Controller::class, 'sosPlaceContact'])
                ->middleware('throttle:20,1')
                ->where('placeId', '[A-Za-z0-9_\\\\-]+')
                ->name('sos.place');

            /*
             | Last, so the literal segments above are not swallowed by the
             | parameter — the same ordering rule as users/search.
             */
            Route::post('sos/{uuid}', [V1Controller::class, 'updateSos'])
                ->middleware('throttle:30,1')
                ->name('sos.update');

            Route::post('sos/{uuid}/end', [V1Controller::class, 'endSos'])
                ->middleware('throttle:30,1')
                ->name('sos.end');
        });""",
    done_marker="'sosOverview'",
)


# ----------------------------------------------------------- V1Controller

patch(
    "app/Http/Controllers/Api/V1/V1Controller.php",
    "V1Controller: sos request imports",
    "use App\\Http\\Requests\\Api\\V1\\Safety\\CheckInRequest;",
    "use App\\Http\\Requests\\Api\\V1\\Safety\\CheckInRequest;\n"
    "use App\\Http\\Requests\\Api\\V1\\Safety\\NearbyPlacesRequest;\n"
    "use App\\Http\\Requests\\Api\\V1\\Safety\\StartSosRequest;",
    done_marker="Safety\\StartSosRequest;",
)

patch(
    "app/Http/Controllers/Api/V1/V1Controller.php",
    "V1Controller: sos service imports",
    "use App\\Services\\Safety\\SafetyService;",
    "use App\\Services\\Safety\\EmergencyDirectory;\n"
    "use App\\Services\\Safety\\PlacesService;\n"
    "use App\\Services\\Safety\\SafetyService;\n"
    "use App\\Services\\Safety\\SosService;",
    done_marker="use App\\Services\\Safety\\SosService;",
)

patch(
    "app/Http/Controllers/Api/V1/V1Controller.php",
    "V1Controller: constructor",
    "        private readonly LocationService $locations,\n    ) {",
    "        private readonly LocationService $locations,\n"
    "        private readonly SosService $sos,\n"
    "        private readonly PlacesService $places,\n"
    "        private readonly EmergencyDirectory $directory,\n    ) {",
    done_marker="private readonly SosService $sos,",
)

patch(
    "app/Http/Controllers/Api/V1/V1Controller.php",
    "V1Controller: sos endpoints",
    """    public function checkInHistory(Request $request): JsonResponse
    {
        $days = (int) $request->integer('days', 30);
        $days = max(1, min(365, $days));

        return $this->ok($this->safety->history($request->user(), $days), 'OK');
    }""",
    '''    public function checkInHistory(Request $request): JsonResponse
    {
        $days = (int) $request->integer('days', 30);
        $days = max(1, min(365, $days));

        return $this->ok($this->safety->history($request->user(), $days), 'OK');
    }

    /*
    |--------------------------------------------------------------------------
    | SOS
    |--------------------------------------------------------------------------
    |
    | One rule runs through all of these: the emergency numbers must appear
    | even when everything else has failed. They come from EmergencyDirectory,
    | which is a plain PHP array and touches no network, no cache and no
    | Google. Nearby places are the enhancement; the numbers are the feature.
    |
    */

    /**
     * GET /api/v1/sos
     *
     * The screen, cold: every service with its numbers and guidance, plus
     * whatever alert is already running.
     */
    public function sosOverview(Request $request): JsonResponse
    {
        return $this->ok($this->sos->overview($request->user()), 'OK');
    }

    /**
     * POST /api/v1/sos   { category?, latitude?, longitude?, note? }
     *
     * Raise the alarm. Records it, opens family location sharing, and tells
     * every family member with the app open.
     *
     * Idempotent: pressing twice returns the alert already running rather
     * than starting a second one. People double-tap buttons they press in a
     * panic, and one emergency must not become two alarms.
     */
    public function startSos(StartSosRequest $request): JsonResponse
    {
        return $this->ok(
            $this->sos->start($request->user(), $request->validated()),
            'Help is being alerted.',
        );
    }

    /**
     * POST /api/v1/sos/{uuid}   { category?, note?, latitude?, longitude? }
     *
     * Attach a category once the person has worked out who they need, or
     * refresh the position once a better fix arrives.
     */
    public function updateSos(StartSosRequest $request, string $uuid): JsonResponse
    {
        return $this->ok(
            $this->sos->update($request->user(), $uuid, $request->validated()),
            'OK',
        );
    }

    /**
     * POST /api/v1/sos/{uuid}/end   { status: resolved|cancelled|false_alarm }
     */
    public function endSos(Request $request, string $uuid): JsonResponse
    {
        return $this->ok($this->sos->end(
            $request->user(),
            $uuid,
            (string) $request->input('status', 'resolved'),
        ), 'Alert ended.');
    }

    /**
     * GET /api/v1/sos/history
     */
    public function sosHistory(Request $request): JsonResponse
    {
        return $this->ok(
            $this->sos->history($request->user(), (int) $request->integer('page', 1)),
            'OK',
        );
    }

    /**
     * GET /api/v1/sos/nearby?category=police&latitude=&longitude=
     * GET /api/v1/sos/nearby?query=apollo&latitude=&longitude=
     *
     * Nearest places of a kind, or a typed search. Proxied and cached here
     * rather than called from the app: the key is IP-restricted to this
     * server, and one paid lookup serves everybody in the same neighbourhood
     * for a week.
     *
     * Never carries phone numbers — those bill at a scarcer tier and are
     * fetched one at a time, on tap, through the endpoint below.
     */
    public function sosNearby(NearbyPlacesRequest $request): JsonResponse
    {
        $lat = (float) $request->validated('latitude');
        $lng = (float) $request->validated('longitude');

        $query = $request->validated('query');

        if ($query !== null && $query !== '') {
            return $this->ok([
                'places' => $this->places->search($query, $lat, $lng),
                'source' => 'search',
            ], 'OK');
        }

        $category = (string) $request->validated('category');
        $types = $this->directory->searchTypes($category);

        if ($types === []) {
            // A category with nothing to search for is not an error — several
            // of them are phone-only by design, and the client should show
            // the numbers without an empty list underneath.
            return $this->ok(['places' => [], 'source' => 'none'], 'OK');
        }

        return $this->ok([
            'places' => $this->places->nearby(
                $types,
                $lat,
                $lng,
                (int) ($request->validated('radius') ?? PlacesService::DEFAULT_RADIUS),
            ),
            'source' => 'nearby',
        ], 'OK');
    }

    /**
     * GET /api/v1/sos/places/{placeId}
     *
     * One place's phone number, for the Call button.
     *
     * The single most expensive call in the API — Google bills contact
     * details at its Enterprise tier — so it exists on its own, is throttled
     * hardest, and must only ever fire when somebody has actually tapped Call
     * on one specific place. Fetching numbers for a list of twenty would cost
     * twenty of these to answer a question nobody asked.
     */
    public function sosPlaceContact(string $placeId): JsonResponse
    {
        $contact = $this->places->contact($placeId);

        if ($contact === null) {
            return $this->fail('We could not get details for that place.', null, 404);
        }

        return $this->ok($contact, 'OK');
    }''',
    done_marker="public function sosOverview(",
)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    if failures:
        print("\n\033[31mrefusing to write — anchors did not match:\033[0m")

        for line in failures:
            print(f"  \033[31m✗\033[0m {line}")

        return 1

    for line in skipped:
        print(f"  \033[33m·\033[0m {line}")

    for line in applied:
        print(f"  \033[32m✓\033[0m {line}")

    if args.dry_run:
        print("\ndry run — nothing written")

        return 0

    for path, text in pending.items():
        path.write_text(text, encoding="utf-8", newline="\n")

    print(f"\n{len(pending)} files written")

    return 0


if __name__ == "__main__":
    sys.exit(main())
