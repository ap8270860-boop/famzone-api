"""
Make an empty nearby-search result explain itself.

`GOOGLE_PLACES_KEY` being empty made PlacesService return [] without calling
Google, and the endpoint answered 200 with an empty list and no reason. "Not
configured", "Google refused the key", "quota gone" and "genuinely nothing
within 5 km" all looked identical from the app — four different problems with
four different fixes, presented as one blank screen.

So the service now records *why* it gave up, the endpoint passes that through,
and the screen says it out loud.

    python fix_places_reason.py --dry-run
    python fix_places_reason.py
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


# ---------------------------------------------------------------------------
# PlacesService: remember why.
# ---------------------------------------------------------------------------

patch(
    "app/Services/Safety/PlacesService.php",
    "places: a failure reason",
    """    public function configured(): bool
    {
        return ! empty(config('services.google.places_key'));
    }""",
    """    /**
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
    }""",
    done_marker="private ?string $failure = null;",
)

patch(
    "app/Services/Safety/PlacesService.php",
    "places: reason on the nearby guard",
    """        if ($types === [] || ! $this->configured()) {
            return [];
        }""",
    """        if ($types === [] || ! $this->configured()) {
            if (! $this->configured()) $this->notConfigured();

            return [];
        }""",
    done_marker="if ($types === [] || ! $this->configured()) {\n"
                "            if (! $this->configured())",
)

patch(
    "app/Services/Safety/PlacesService.php",
    "places: reason on the search guard",
    """        if (mb_strlen($query) < 2 || ! $this->configured()) {
            return [];
        }""",
    """        if (mb_strlen($query) < 2 || ! $this->configured()) {
            if (! $this->configured()) $this->notConfigured();

            return [];
        }""",
    done_marker="if (mb_strlen($query) < 2 || ! $this->configured()) {\n"
                "            if (! $this->configured())",
)

patch(
    "app/Services/Safety/PlacesService.php",
    "places: reason on the contact guard",
    """        if ($placeId === '' || ! $this->configured()) {
            return null;
        }""",
    """        if ($placeId === '' || ! $this->configured()) {
            if (! $this->configured()) $this->notConfigured();

            return null;
        }""",
    done_marker="if ($placeId === '' || ! $this->configured()) {\n"
                "            if (! $this->configured())",
)

patch(
    "app/Services/Safety/PlacesService.php",
    "places: name what Google actually said",
    """        if (! $response->successful()) {
            Log::warning('places request rejected', [
                'path' => $path,
                'status' => $response->status(),
                // Google puts the actual reason here — a wrong key, a
                // disabled API, an exhausted quota all look identical
                // without it.
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return null;
        }""",
    """        if (! $response->successful()) {
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
        }""",
    done_marker="$this->failure = $this->explain(",
)

patch(
    "app/Services/Safety/PlacesService.php",
    "places: the explain() helper",
    """    private function headers(string $fields): array
    {""",
    """    /**
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
    {""",
    done_marker="private function explain(int $status",
)


# ---------------------------------------------------------------------------
# The endpoint: pass it through.
# ---------------------------------------------------------------------------

patch(
    "app/Http/Controllers/Api/V1/V1Controller.php",
    "V1Controller: nearby says why it is empty",
    """        if ($query !== null && $query !== '') {
            return $this->ok([
                'places' => $this->places->search($query, $lat, $lng),
                'source' => 'search',
            ], 'OK');
        }""",
    """        if ($query !== null && $query !== '') {
            $places = $this->places->search($query, $lat, $lng);

            return $this->ok([
                'places' => $places,
                'source' => 'search',
                'available' => $this->places->configured(),
                'reason' => $places === [] ? $this->places->failure() : null,
            ], 'OK');
        }""",
    done_marker="'source' => 'search',\n                'available' =>",
)

patch(
    "app/Http/Controllers/Api/V1/V1Controller.php",
    "V1Controller: nearby result carries availability",
    """        return $this->ok([
            'places' => $this->places->nearby(
                $types,
                $lat,
                $lng,
                (int) ($request->validated('radius') ?? PlacesService::DEFAULT_RADIUS),
            ),
            'source' => 'nearby',
        ], 'OK');""",
    """        $places = $this->places->nearby(
            $types,
            $lat,
            $lng,
            (int) ($request->validated('radius') ?? PlacesService::DEFAULT_RADIUS),
        );

        return $this->ok([
            'places' => $places,
            'source' => 'nearby',
            'available' => $this->places->configured(),
            'reason' => $places === [] ? $this->places->failure() : null,
        ], 'OK');""",
    done_marker="'source' => 'nearby',\n            'available' =>",
)

patch(
    "app/Http/Controllers/Api/V1/V1Controller.php",
    "V1Controller: the no-search-types case says so too",
    """            return $this->ok(['places' => [], 'source' => 'none'], 'OK');""",
    """            return $this->ok([
                'places' => [],
                'source' => 'none',
                'available' => true,
                'reason' => null,
            ], 'OK');""",
    done_marker="'source' => 'none',\n                'available' => true,",
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
