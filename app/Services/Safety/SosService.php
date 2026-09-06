<?php

namespace App\Services\Safety;

use App\Events\Safety\SosEnded;
use App\Events\Safety\SosRaised;
use App\Models\FamilyMember;
use App\Models\LocationShare;
use App\Models\SosAlert;
use App\Models\User;
use App\Services\Location\LocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Raising, running and closing an alarm.
 *
 * Three principles, in the order they matter.
 *
 * **Nothing here can fail loudly.** Pressing SOS must record the alert and
 * tell somebody, and if anything else goes wrong along the way — the location
 * share, the broadcast, the queue — it goes wrong quietly and the alert
 * stands. Everything optional is wrapped; the write is not.
 *
 * **The alarm comes before the question.** Category is nullable and set
 * afterwards. Somebody in trouble presses a button; asking them to first
 * choose between fourteen services would put a menu between a frightened
 * person and their family.
 *
 * **Location is snapshotted, not referenced.** Where they were when they
 * pressed it is a permanent fact about the alert. Reading it from a live
 * users row would answer a different question every time.
 */
class SosService
{
    public function __construct(
        private readonly LocationService $locations,
        private readonly EmergencyDirectory $directory,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Raising
    |--------------------------------------------------------------------------
    */

    /**
     * Press the button.
     *
     * Idempotent: a second press while one is already running returns the
     * running alert rather than starting another. Double-taps are the norm on
     * a button people press in a panic, and two alerts for one emergency
     * would double the messages their family receives at the worst moment.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function start(User $me, array $input = []): array
    {
        $existing = $this->activeFor($me);

        if ($existing !== null) {
            return $this->payload($me, $existing, replayed: true);
        }

        $fix = $this->fixFrom($input);

        $alert = DB::transaction(function () use ($me, $input, $fix) {
            $alert = new SosAlert([
                'category' => $input['category'] ?? null,
                'status' => SosAlert::STATUS_ACTIVE,
                'note' => $input['note'] ?? null,
                'started_at' => now(),
                ...$fix,
            ]);

            // Not Fillable, and mass assignment on this codebase fails
            // silently rather than loudly. Assigned, not passed.
            $alert->user_id = $me->id;
            $alert->save();

            $me->forceFill(['last_sos_at' => now()])->save();

            return $alert;
        });

        /*
         | Everything past this point is best-effort.
         |
         | The alert exists and is committed. A failure to open a location
         | share or reach Reverb must degrade the alert, never discard it —
         | so each step is separately guarded and separately logged.
         */
        $this->openShare($me, $alert);

        $family = $this->familyOf($me);

        if ($family !== []) {
            $alert->forceFill(['notified_count' => count($family)])->save();
        }

        try {
            SosRaised::dispatch($alert->fresh(['user']), $family);
        } catch (\Throwable $e) {
            Log::error('sos broadcast failed', [
                'alert' => $alert->uuid,
                'error' => $e->getMessage(),
            ]);
        }

        /*
         | Seam: a persisted notification row per family member, so the alert
         | is still there when they open the app tomorrow. Deliberately not
         | wired here yet — it belongs with push notifications, which is the
         | thing that actually makes an SOS reach somebody whose phone is in
         | their pocket. Until then the broadcast reaches whoever has the app
         | open, and the history endpoint is the durable record.
         */

        return $this->payload($me, $alert->fresh(), replayed: false);
    }

    /**
     * Attach a category, or a note, to a running alert.
     *
     * Separate from start() because it happens seconds later, once the person
     * has read the list and knows whether they want police or an ambulance.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(User $me, string $uuid, array $input): array
    {
        $alert = $this->findMine($me, $uuid);

        abort_unless($alert->isActive(), 422, 'That alert has already ended.');

        $alert->forceFill(array_filter([
            'category' => $input['category'] ?? null,
            'note' => $input['note'] ?? null,
        ], static fn ($value) => $value !== null))->save();

        // A better fix may have arrived since the button was pressed —
        // pressing SOS indoors often produces a coarse first position.
        $fix = $this->fixFrom($input);

        if ($fix !== [] && isset($fix['latitude'])) {
            $alert->forceFill($fix)->save();
        }

        return $this->payload($me, $alert->fresh(), replayed: false);
    }

    /*
    |--------------------------------------------------------------------------
    | Closing
    |--------------------------------------------------------------------------
    */

    /**
     * End it.
     *
     * The status matters and is not cosmetic. "Cancelled" and "false alarm"
     * both close an alert, but they read completely differently in a history
     * six months later — and a person who can say "that was my pocket"
     * without it looking like a real emergency they backed out of is a person
     * who leaves the button enabled.
     *
     * @return array<string, mixed>
     */
    public function end(User $me, string $uuid, string $status): array
    {
        abort_unless(
            in_array($status, SosAlert::CLOSING_STATUSES, true),
            422,
            'That is not a way an alert can end.',
        );

        $alert = $this->findMine($me, $uuid);

        if (! $alert->isActive()) {
            return $this->payload($me, $alert, replayed: true);
        }

        $alert->forceFill(['status' => $status, 'ended_at' => now()])->save();

        /*
         | Only a share this alert opened is closed.
         |
         | location_share_id is null when the person already had family
         | sharing running for their own reasons — ending their SOS must not
         | quietly switch that off as a side effect.
         */
        if ($alert->location_share_id !== null) {
            try {
                $share = LocationShare::find($alert->location_share_id);

                if ($share !== null) {
                    $this->locations->stop($me, $share->uuid);
                }
            } catch (\Throwable $e) {
                Log::warning('sos share stop failed', [
                    'alert' => $alert->uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            SosEnded::dispatch($alert->fresh(['user']), $this->familyOf($me));
        } catch (\Throwable $e) {
            Log::error('sos end broadcast failed', [
                'alert' => $alert->uuid,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->payload($me, $alert->fresh(), replayed: false);
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * The SOS screen, cold.
     *
     * Catalogue and running alert in one response, because the screen cannot
     * usefully render half of it — and because the numbers must appear even
     * when everything else has failed.
     *
     * @return array<string, mixed>
     */
    public function overview(User $me): array
    {
        $active = $this->activeFor($me);

        return [
            'active' => $active === null ? null : $this->present($active),
            'services' => $this->directory->all(),
            'disclaimer' => EmergencyDirectory::DISCLAIMER,

            /*
             | Whether nearby search will work at all.
             |
             | Handed over so the client can hide a search button that cannot
             | succeed, rather than offering one that returns an empty list
             | and looks broken.
             */
            'search_available' => app(PlacesService::class)->configured(),

            'family_count' => count($this->familyOf($me)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function history(User $me, int $page = 1): array
    {
        $alerts = SosAlert::query()
            ->where('user_id', $me->id)
            ->newestFirst()
            ->paginate(20, ['*'], 'page', max(1, $page));

        return [
            'alerts' => collect($alerts->items())
                ->map(fn (SosAlert $alert) => $this->present($alert))
                ->all(),
            'page' => $alerts->currentPage(),
            'has_more' => $alerts->hasMorePages(),
            'total' => $alerts->total(),
        ];
    }

    public function activeFor(User $me): ?SosAlert
    {
        return SosAlert::query()
            ->where('user_id', $me->id)
            ->active()
            ->newestFirst()
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function findMine(User $me, string $uuid): SosAlert
    {
        $alert = SosAlert::query()
            ->where('user_id', $me->id)
            ->where('uuid', $uuid)
            ->first();

        abort_if($alert === null, 404, 'That alert does not exist.');

        return $alert;
    }

    /**
     * Start family location sharing alongside the alert.
     *
     * Reuses a share the person already had rather than replacing it, so
     * ending the SOS later can end only what the SOS began.
     */
    private function openShare(User $me, SosAlert $alert): void
    {
        try {
            $existing = LocationShare::query()
                ->live()
                ->where('user_id', $me->id)
                ->where('audience', LocationShare::AUDIENCE_FAMILY)
                ->first();

            if ($existing !== null) {
                return;
            }

            $result = $this->locations->share($me, [
                'audience' => LocationShare::AUDIENCE_FAMILY,
                'latitude' => $alert->latitude === null ? null : (float) $alert->latitude,
                'longitude' => $alert->longitude === null ? null : (float) $alert->longitude,
            ]);

            $uuid = $result['share']['id'] ?? null;

            if ($uuid === null) {
                return;
            }

            $share = LocationShare::where('uuid', $uuid)->first();

            if ($share !== null) {
                $alert->forceFill(['location_share_id' => $share->id])->save();
            }
        } catch (\Throwable $e) {
            /*
             | An alarm without a live map is still an alarm. Logged, not
             | raised — the family being told is the part that matters, and
             | that has already happened by the time this runs.
             */
            Log::warning('sos share open failed', [
                'alert' => $alert->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Accepted family, from both ends of the link.
     *
     * @return array<int, User>
     */
    private function familyOf(User $me): array
    {
        return FamilyMember::query()
            ->accepted()
            ->involving($me->id)
            ->with(['owner', 'member'])
            ->get()
            ->map(fn (FamilyMember $link) => $link->counterpartFor($me))
            ->filter()
            ->unique('id')
            ->values()
            ->all();
    }

    /**
     * The position half of the input, if any of it is there.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function fixFrom(array $input): array
    {
        if (! isset($input['latitude'], $input['longitude'])) {
            return [];
        }

        return [
            'latitude' => (float) $input['latitude'],
            'longitude' => (float) $input['longitude'],
            'accuracy' => isset($input['accuracy'])
                ? (int) round((float) $input['accuracy'])
                : null,
            'battery_level' => isset($input['battery_level'])
                ? (int) $input['battery_level']
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $me, SosAlert $alert, bool $replayed): array
    {
        return [
            'alert' => $this->present($alert),
            'replayed' => $replayed,
            'services' => $this->directory->all(),
            'disclaimer' => EmergencyDirectory::DISCLAIMER,
            'family_count' => $alert->notified_count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(SosAlert $alert): array
    {
        return [
            'id' => $alert->uuid,
            'category' => $alert->category,
            'status' => $alert->status,
            'active' => $alert->isActive(),

            'latitude' => $alert->latitude === null ? null : (float) $alert->latitude,
            'longitude' => $alert->longitude === null ? null : (float) $alert->longitude,
            'accuracy' => $alert->accuracy,
            'address' => $alert->address,
            'has_location' => $alert->hasLocation(),

            'battery_level' => $alert->battery_level,
            'note' => $alert->note,
            'notified_count' => (int) $alert->notified_count,

            'started_at' => $alert->started_at?->toIso8601String(),
            'ended_at' => $alert->ended_at?->toIso8601String(),

            /*
             | How long it ran, in seconds. Rendered by the client, because a
             | live alert's duration has to tick — a label baked here would be
             | wrong one second after it was sent.
             */
            'duration_seconds' => $alert->started_at === null
                ? null
                : ($alert->ended_at ?? now())->diffInSeconds($alert->started_at, true),
        ];
    }
}
