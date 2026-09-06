"""
Wire the location feature into the files that already exist.

Every replacement is guarded and idempotent: the anchor must be present
exactly as many times as expected, and a patch whose result is already in
place is skipped rather than applied twice. An unguarded str.replace that
misses does nothing and says nothing, which is how a 500 gets shipped.

    python apply_location.py            # patch in place
    python apply_location.py --dry-run  # report only
"""

from __future__ import annotations

import argparse
import pathlib
import sys

ROOT = pathlib.Path(__file__).resolve().parent

applied: list[str] = []
skipped: list[str] = []
failures: list[str] = []


def patch(rel: str, label: str, anchor: str, replacement: str,
          *, expect: int = 1, done_marker: str | None = None) -> None:
    path = ROOT / rel

    if not path.exists():
        failures.append(f"{label}: {rel} not found")
        return

    # Pending first: two patches to the same file must compose, not race.
    text = pending.get(path) or path.read_text(encoding="utf-8")

    marker = done_marker if done_marker is not None else replacement.strip().splitlines()[0]

    if marker in text:
        skipped.append(f"{label} (already applied)")
        return

    found = text.count(anchor)

    if found != expect:
        failures.append(
            f"{label}: anchor found {found}x in {rel}, expected {expect}"
        )
        return

    pending[path] = text.replace(anchor, replacement, expect)
    applied.append(f"{label}  ->  {rel}")


def append(rel: str, label: str, addition: str, done_marker: str) -> None:
    path = ROOT / rel

    if not path.exists():
        failures.append(f"{label}: {rel} not found")
        return

    text = pending.get(path, path.read_text(encoding="utf-8"))

    if done_marker in text:
        skipped.append(f"{label} (already applied)")
        return

    pending[path] = text.rstrip("\n") + "\n" + addition
    applied.append(f"{label}  ->  {rel}")


pending: dict[pathlib.Path, str] = {}


# ---------------------------------------------------------------- Message

patch(
    "app/Models/Message.php",
    "Message: TYPE_LOCATION",
    "    public const TYPE_AUDIO = 'audio';",
    "    public const TYPE_AUDIO = 'audio';\n"
    "\n"
    "    /** A dropped pin, or a live share announcing itself in a thread. */\n"
    "    public const TYPE_LOCATION = 'location';",
    done_marker="TYPE_LOCATION",
)

patch(
    "app/Models/Message.php",
    "Message: coordinate casts",
    "            'seq' => 'integer',\n"
    "            'forwarded' => 'boolean',\n"
    "            'edited_at' => 'datetime',",
    "            'seq' => 'integer',\n"
    "            'forwarded' => 'boolean',\n"
    "            'edited_at' => 'datetime',\n"
    "            'latitude' => 'decimal:7',\n"
    "            'longitude' => 'decimal:7',",
    done_marker="'latitude' => 'decimal:7',",
)


# ------------------------------------------------------------------- User

patch(
    "app/Models/User.php",
    "User: live location casts",
    "            'last_latitude' => 'decimal:7',\n"
    "            'last_longitude' => 'decimal:7',\n"
    "            'last_location_at' => 'datetime',",
    "            'last_latitude' => 'decimal:7',\n"
    "            'last_longitude' => 'decimal:7',\n"
    "            'last_location_at' => 'datetime',\n"
    "            'last_location_accuracy' => 'integer',\n"
    "            'last_location_speed' => 'float',\n"
    "            'last_location_heading' => 'float',\n"
    "            'last_location_moving' => 'boolean',",
    done_marker="'last_location_accuracy' => 'integer',",
)


# ------------------------------------------------------------- ChatService

patch(
    "app/Services/Chat/ChatService.php",
    "ChatService: LocationService import",
    "use App\\Services\\Social\\RelationshipService;",
    "use App\\Services\\Location\\LocationService;\n"
    "use App\\Services\\Social\\RelationshipService;",
    done_marker="use App\\Services\\Location\\LocationService;",
)

patch(
    "app/Services/Chat/ChatService.php",
    "ChatService: location block on presentMessage",
    "            'reply_to' => $this->presentQuote($message->replyTo),",
    "            /*\n"
    "             | Coordinates, when the message is a pin.\n"
    "             |\n"
    "             | Resolved through LocationService rather than read straight\n"
    "             | off the row, because a live share's bubble has to know\n"
    "             | whether it is still running — and that is a fact about the\n"
    "             | share, not about the message. Null for every other type,\n"
    "             | which is nearly all of them, at the cost of one comparison.\n"
    "             */\n"
    "            'location' => $deleted || $message->type !== Message::TYPE_LOCATION\n"
    "                ? null\n"
    "                : app(LocationService::class)->presentMessageLocation($message),\n"
    "\n"
    "            'reply_to' => $this->presentQuote($message->replyTo),",
    done_marker="app(LocationService::class)->presentMessageLocation",
)


# --------------------------------------------------------------- channels

append(
    "routes/channels.php",
    "channels: location.{uuid}",
    '''
/**
 * One person's live position.
 *
 * Named after the person being watched rather than the person watching, so
 * six family members following one phone cost the server one frame rather
 * than six.
 *
 * The whole privacy model is the call to canView() below: a subscribe is
 * allowed only while a live share grants it, and a frame never reaches a
 * client that was not allowed to subscribe. There is no client-side
 * filtering to get wrong.
 *
 * Authorisation runs once, at subscribe. What closes the window afterwards
 * is that the server stops publishing the moment a share ends, and
 * `location.share.ended` tells the client to drop the channel — the same
 * belt-and-braces the conversation channel uses for blocks.
 */
Broadcast::channel('location.{uuid}', function (User $user, string $uuid) {
    $sharer = User::where('uuid', $uuid)->first();

    if ($sharer === null) {
        return false;
    }

    return app(LocationService::class)->canView($user, $sharer);
});
''',
    done_marker="Broadcast::channel('location.{uuid}'",
)

patch(
    "routes/channels.php",
    "channels: LocationService import",
    "use App\\Models\\User;",
    "use App\\Models\\User;\nuse App\\Services\\Location\\LocationService;",
    done_marker="use App\\Services\\Location\\LocationService;",
)


# ------------------------------------------------------------------ routes

patch(
    "routes/api.php",
    "routes: location group",
    "        Route::post('presence/ping', [V1Controller::class, 'presencePing'])\n"
    "            ->middleware('throttle:120,1')\n"
    "            ->name('presence.ping');",
    "        Route::post('presence/ping', [V1Controller::class, 'presencePing'])\n"
    "            ->middleware('throttle:120,1')\n"
    "            ->name('presence.ping');\n"
    "\n"
    "\n"
    "        /*\n"
    "         | Live location.\n"
    "         |\n"
    "         | `ping` is the highest-frequency authenticated endpoint in the\n"
    "         | API by an order of magnitude — a phone in a car sends one\n"
    "         | every five seconds — so its limit is set for a buffer flush\n"
    "         | after a dead spot rather than for the steady state. Everything\n"
    "         | else here is a deliberate human action and is capped like one.\n"
    "         |\n"
    "         | The server decides the sampling rate and hands it back on\n"
    "         | every ping; the client obeys. See LocationService::tracking.\n"
    "         */\n"
    "        Route::prefix('location')->name('location.')->group(function () {\n"
    "            Route::get('live', [V1Controller::class, 'liveLocations'])\n"
    "                ->middleware('throttle:120,1')\n"
    "                ->name('live');\n"
    "\n"
    "            Route::post('ping', [V1Controller::class, 'pingLocation'])\n"
    "                ->middleware('throttle:600,1')\n"
    "                ->name('ping');\n"
    "\n"
    "            Route::post('share', [V1Controller::class, 'shareLocation'])\n"
    "                ->middleware('throttle:30,1')\n"
    "                ->name('share');\n"
    "\n"
    "            Route::post('stop', [V1Controller::class, 'stopLocation'])\n"
    "                ->middleware('throttle:60,1')\n"
    "                ->name('stop');\n"
    "\n"
    "            Route::post('pin', [V1Controller::class, 'pinLocation'])\n"
    "                ->middleware('throttle:30,1')\n"
    "                ->name('pin');\n"
    "\n"
    "            /*\n"
    "             | Last, so the literal segments above are not swallowed by\n"
    "             | the parameter — the same ordering rule as users/search.\n"
    "             */\n"
    "            Route::get('{uuid}/trail', [V1Controller::class, 'locationTrail'])\n"
    "                ->middleware('throttle:60,1')\n"
    "                ->name('trail');\n"
    "        });",
    done_marker="Route::prefix('location')->name('location.')",
)


# ----------------------------------------------------------- V1Controller

patch(
    "app/Http/Controllers/Api/V1/V1Controller.php",
    "V1Controller: request imports",
    "use App\\Http\\Requests\\Api\\V1\\Posts\\CreatePostRequest;",
    "use App\\Http\\Requests\\Api\\V1\\Location\\PinLocationRequest;\n"
    "use App\\Http\\Requests\\Api\\V1\\Location\\PingLocationRequest;\n"
    "use App\\Http\\Requests\\Api\\V1\\Location\\ShareLocationRequest;\n"
    "use App\\Http\\Requests\\Api\\V1\\Posts\\CreatePostRequest;",
    done_marker="Location\\ShareLocationRequest;",
)

patch(
    "app/Http/Controllers/Api/V1/V1Controller.php",
    "V1Controller: service import",
    "use App\\Services\\Otp\\Exceptions\\OtpException;",
    "use App\\Services\\Location\\LocationService;\n"
    "use App\\Services\\Otp\\Exceptions\\OtpException;",
    done_marker="use App\\Services\\Location\\LocationService;",
)

patch(
    "app/Http/Controllers/Api/V1/V1Controller.php",
    "V1Controller: constructor",
    "        private readonly GroupService $groups,\n    ) {",
    "        private readonly GroupService $groups,\n"
    "        private readonly LocationService $locations,\n    ) {",
    done_marker="private readonly LocationService $locations,",
)

patch(
    "app/Http/Controllers/Api/V1/V1Controller.php",
    "V1Controller: location endpoints",
    "    public function presencePing(Request $request): JsonResponse\n"
    "    {\n"
    "        return $this->ok($this->presence->ping($request->user()), 'OK');\n"
    "    }",
    '''    public function presencePing(Request $request): JsonResponse
    {
        return $this->ok($this->presence->ping($request->user()), 'OK');
    }

    /*
    |--------------------------------------------------------------------------
    | Live location
    |--------------------------------------------------------------------------
    */

    /**
     * GET /api/v1/location/live
     *
     * Everything the map screen needs to paint itself from cold: who is
     * currently sharing with me, where each of them is, what I am sharing,
     * and how often my own phone should be looking.
     *
     * The socket carries deltas after this and nothing else. A dropped
     * socket is therefore a stale map rather than an empty one — the same
     * contract as chat, for the same reason.
     */
    public function liveLocations(Request $request): JsonResponse
    {
        return $this->ok($this->locations->live($request->user()), 'OK');
    }

    /**
     * POST /api/v1/location/ping   { fixes: [ {latitude, longitude, ...} ] }
     *
     * A buffer of readings from one phone. Accepts a bare fix too.
     *
     * Always answers with what to do next, including "stop" — a client that
     * never hears stop from the server is a client that tracks forever.
     */
    public function pingLocation(PingLocationRequest $request): JsonResponse
    {
        return $this->ok(
            $this->locations->ping($request->user(), $request->validated('fixes')),
            'OK',
        );
    }

    /**
     * POST /api/v1/location/share   { audience, conversation_id?, minutes? }
     *
     * Begin sharing. A conversation share announces itself with a message in
     * the thread, in the same call — there is no path that starts one
     * silently.
     */
    public function shareLocation(ShareLocationRequest $request): JsonResponse
    {
        return $this->ok(
            $this->locations->share($request->user(), $request->validated()),
            'Sharing your location.',
        );
    }

    /**
     * POST /api/v1/location/stop   { share_id? }
     *
     * Without a share_id this stops everything, which is what the status-bar
     * button does: the action somebody is most likely to take in a hurry
     * should not first ask them which share they meant.
     */
    public function stopLocation(Request $request): JsonResponse
    {
        return $this->ok(
            $this->locations->stop($request->user(), $request->input('share_id')),
            'Location sharing stopped.',
        );
    }

    /**
     * POST /api/v1/location/pin   { conversation_id, latitude, longitude }
     *
     * A static "here is where I am". No share, nothing to expire.
     */
    public function pinLocation(PinLocationRequest $request): JsonResponse
    {
        return $this->created($this->locations->pin(
            $request->user(),
            $request->validated('conversation_id'),
            (float) $request->validated('latitude'),
            (float) $request->validated('longitude'),
        ), 'Location sent.');
    }

    /**
     * GET /api/v1/location/{uuid}/trail?since=
     *
     * The recent path behind somebody's marker. Capped at 24 hours and
     * thinned on the way out.
     */
    public function locationTrail(Request $request, string $uuid): JsonResponse
    {
        return $this->ok($this->locations->trail(
            $request->user(),
            $uuid,
            $request->query('since'),
        ), 'OK');
    }''',
    done_marker="public function liveLocations(",
)


# ------------------------------------------------------------------- main

def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    if failures:
        print("\n\033[31mrefusing to write — anchors did not match:\033[0m")
        for line in failures:
            print(f"  ✗ {line}")
        print("\nNothing was changed.")

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
