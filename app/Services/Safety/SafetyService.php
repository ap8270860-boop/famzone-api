<?php

namespace App\Services\Safety;

use App\Models\SafetyCheckIn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Everything the home screen's two safety cards are made of.
 *
 * Both cards are rendered from a single payload produced here, and the
 * check-in endpoint returns that same payload. That is deliberate: the status
 * card and the check-in card are two views of one fact, and if the client had
 * to stitch them together from separate responses they would drift apart the
 * moment one request failed.
 *
 * The copy lives here rather than in the client for the same reason — the
 * Flutter app and the web dashboard should never disagree about what "All
 * Safe" means or how it is worded.
 */
class SafetyService
{
    public const STATE_SAFE = 'all_safe';
    public const STATE_ATTENTION = 'attention';
    public const STATE_ALERT = 'alert';

    /** How many days the home screen's dot strip shows. */
    private const RECENT_DAYS = 7;

    /*
    |--------------------------------------------------------------------------
    | Reads
    |--------------------------------------------------------------------------
    */

    /**
     * The whole safety picture for one user.
     *
     * @return array<string, mixed>
     */
    public function status(User $user): array
    {
        $now = $this->localNow($user);
        $today = $now->toDateString();

        $checkIn = $user->checkIns()
            ->where('check_in_date', $today)
            ->first();

        $reminder = $this->reminderMoment($user, $now);
        $overdue = $checkIn === null
            && $reminder !== null
            && $now->greaterThanOrEqualTo($reminder);

        $state = $this->deriveState($user, $checkIn, $overdue);

        return [
            'state' => $state,
            'tone' => $this->tone($state),
            'headline' => $this->headline($state),
            'detail' => $this->detail($state, $checkIn, $now, $reminder),
            'as_of' => $now->toIso8601String(),
            'timezone' => $now->timezoneName,

            'check_in' => [
                'done_today' => $checkIn !== null,
                'date' => $today,
                'status' => $checkIn?->status,
                'source' => $checkIn?->source,
                'note' => $checkIn?->note,
                'checked_in_at' => $checkIn?->checked_in_at?->toIso8601String(),
                'checked_in_label' => $checkIn === null
                    ? null
                    : $checkIn->checked_in_at->setTimezone($now->timezone)->format('g:i A'),

                'overdue' => $overdue,
                'reminder_at' => $user->check_in_reminder_at
                    ? CarbonImmutable::parse($user->check_in_reminder_at)->format('H:i')
                    : null,
                'reminder_label' => $user->check_in_reminder_at
                    ? CarbonImmutable::parse($user->check_in_reminder_at)->format('g:i A')
                    : null,

                // Midnight tonight, local. What the card counts down to.
                'next_due_at' => $now->addDay()->startOfDay()->toIso8601String(),

                'streak' => [
                    'current' => (int) $user->check_in_streak,
                    'longest' => (int) $user->longest_check_in_streak,
                ],

                'recent' => $this->recent($user, $now),
            ],

            // Circles are Phase 1 work. The shape is here so the client can
            // render the row the day it starts returning numbers, and hide it
            // while total is zero rather than inventing members.
            'circle' => [
                'total' => 0,
                'safe' => 0,
                'pending' => 0,
                'alert' => 0,
            ],
        ];
    }

    /**
     * The last N local days, oldest first, each marked done or not.
     *
     * One query for the window rather than one per day.
     *
     * @return list<array<string, mixed>>
     */
    private function recent(User $user, CarbonImmutable $now): array
    {
        $start = $now->subDays(self::RECENT_DAYS - 1)->toDateString();

        $done = $user->checkIns()
            ->whereBetween('check_in_date', [$start, $now->toDateString()])
            ->get(['check_in_date', 'status'])
            ->keyBy(fn (SafetyCheckIn $c) => $c->check_in_date->toDateString());

        $days = [];

        for ($i = self::RECENT_DAYS - 1; $i >= 0; $i--) {
            $day = $now->subDays($i);
            $date = $day->toDateString();
            $row = $done->get($date);

            $days[] = [
                'date' => $date,
                'weekday' => $day->format('D'),
                'initial' => $day->format('D')[0],
                'done' => $row !== null,
                'status' => $row?->status,
                'is_today' => $i === 0,
            ];
        }

        return $days;
    }

    /*
    |--------------------------------------------------------------------------
    | Writes
    |--------------------------------------------------------------------------
    */

    /**
     * Record today's check-in.
     *
     * Idempotent: tapping twice, or a retry after a dropped response, returns
     * the existing check-in rather than failing or creating a second one. The
     * unique index on (user_id, check_in_date) is the real guarantee; the row
     * lock below just stops two simultaneous taps racing into a constraint
     * violation the user would see as an error.
     *
     * @param  array<string, mixed>  $input
     * @return array{created: bool, status: array<string, mixed>}
     */
    public function checkIn(User $user, array $input, ?Request $request = null): array
    {
        $now = $this->localNow($user);
        $today = $now->toDateString();

        $created = DB::transaction(function () use ($user, $input, $request, $now, $today): bool {
            // Serialise concurrent check-ins for this user only.
            $locked = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $existing = $locked->checkIns()->where('check_in_date', $today)->first();

            if ($existing !== null) {
                return false;
            }

            $locked->checkIns()->create([
                // The local calendar day this belongs to...
                'check_in_date' => $today,

                // ...but the instant itself is stored in UTC. Laravel's
                // datetime cast formats whatever Carbon it is given
                // without converting the zone, and reads it back as the
                // app timezone, so handing it a local-zone Carbon writes
                // a wall clock that is later reinterpreted as UTC.
                'checked_in_at' => $now->utc(),
                'status' => $input['status'] ?? SafetyCheckIn::STATUS_SAFE,
                'source' => $input['source'] ?? SafetyCheckIn::SOURCE_MANUAL,
                'note' => $input['note'] ?? null,
                'latitude' => $input['latitude'] ?? null,
                'longitude' => $input['longitude'] ?? null,
                'location_accuracy' => $input['location_accuracy'] ?? null,
                'battery_level' => $input['battery_level'] ?? null,
                'device_type' => $input['device_type'] ?? $locked->device_type,
                'app_version' => $input['app_version'] ?? $locked->app_version,
                'timezone' => $now->timezoneName,
                'ip_address' => $request?->ip(),
            ]);

            // Streak counts consecutive local days. Yesterday having a row is
            // the whole test — the stored counter is only ever extended from
            // an unbroken run, so a gap resets it to today alone.
            $continues = $locked->checkIns()
                ->where('check_in_date', $now->subDay()->toDateString())
                ->exists();

            $streak = $continues ? (int) $locked->check_in_streak + 1 : 1;

            $locked->forceFill([
                'last_check_in_at' => $now->utc(),
                'check_in_streak' => $streak,
                'longest_check_in_streak' => max((int) $locked->longest_check_in_streak, $streak),
            ])->save();

            return true;
        });

        // Re-read so the payload reflects the write, including the counters
        // updated on the locked instance rather than on $user.
        return [
            'created' => $created,
            'status' => $this->status($user->refresh()),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | History
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    public function history(User $user, int $days): array
    {
        $now = $this->localNow($user);
        $start = $now->subDays($days - 1)->startOfDay();

        $rows = $user->checkIns()
            ->whereBetween('check_in_date', [$start->toDateString(), $now->toDateString()])
            ->latestFirst()
            ->get();

        // Out of the days that have actually elapsed since the account was
        // created, not out of the window — somebody four days into using the
        // app has not missed the three days before they signed up.
        $joined = $user->created_at?->setTimezone($now->timezone)->startOfDay();
        $elapsed = $joined !== null && $joined->greaterThan($start)
            ? $joined->diffInDays($now->startOfDay()) + 1
            : $days;
        $elapsed = max(1, min($days, (int) $elapsed));

        return [
            'from' => $start->toDateString(),
            'to' => $now->toDateString(),
            'days' => $days,
            'elapsed_days' => $elapsed,
            'completed' => $rows->count(),
            'rate' => (int) round($rows->count() / $elapsed * 100),

            'check_ins' => $rows->map(fn (SafetyCheckIn $c) => [
                'id' => $c->uuid,
                'date' => $c->check_in_date->toDateString(),
                'checked_in_at' => $c->checked_in_at->toIso8601String(),
                'status' => $c->status,
                'source' => $c->source,
                'note' => $c->note,
                'has_location' => $c->hasLocation(),
            ])->values()->all(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * "Now", in the user's own timezone.
     *
     * A bad timezone string in the database would otherwise throw from deep
     * inside Carbon on a plain profile read, so it is validated here and
     * quietly falls back to the app default.
     */
    private function localNow(User $user): CarbonImmutable
    {
        $zone = $user->timezone;

        try {
            new \DateTimeZone((string) $zone);
        } catch (\Throwable) {
            $zone = null;
        }

        return CarbonImmutable::now($zone ?: config('app.timezone', 'UTC'));
    }

    /** Today's reminder time as a moment, or null when reminders are off. */
    private function reminderMoment(User $user, CarbonImmutable $now): ?CarbonImmutable
    {
        if (blank($user->check_in_reminder_at)) {
            return null;
        }

        $time = CarbonImmutable::parse($user->check_in_reminder_at);

        return $now->setTime($time->hour, $time->minute, 0);
    }

    private function deriveState(User $user, ?SafetyCheckIn $checkIn, bool $overdue): string
    {
        return match (true) {
            $this->hasActiveAlert($user) => self::STATE_ALERT,
            $checkIn?->status === SafetyCheckIn::STATUS_UNSAFE => self::STATE_ALERT,
            $overdue => self::STATE_ATTENTION,
            default => self::STATE_SAFE,
        };
    }

    /**
     * Whether anything is actively wrong.
     *
     * The seam for SOS, which is Phase 1. Returning false rather than leaving
     * the branch out means the day SOS lands, this is the only line that
     * changes and every caller already handles the alert state.
     */
    private function hasActiveAlert(User $user): bool
    {
        return false;
    }

    private function tone(string $state): string
    {
        return match ($state) {
            self::STATE_ALERT => 'critical',
            self::STATE_ATTENTION => 'caution',
            default => 'positive',
        };
    }

    private function headline(string $state): string
    {
        return match ($state) {
            self::STATE_ALERT => 'Needs Attention',
            self::STATE_ATTENTION => 'Check-in Due',
            default => 'All Safe',
        };
    }

    private function detail(
        string $state,
        ?SafetyCheckIn $checkIn,
        CarbonImmutable $now,
        ?CarbonImmutable $reminder,
    ): string {
        if ($state === self::STATE_ALERT) {
            return $checkIn?->status === SafetyCheckIn::STATUS_UNSAFE
                ? 'You marked yourself as not safe today.'
                : 'Something needs your attention.';
        }

        if ($checkIn !== null) {
            $at = $checkIn->checked_in_at->setTimezone($now->timezone)->format('g:i A');

            return "You checked in at {$at}.";
        }

        if ($state === self::STATE_ATTENTION) {
            return "You haven't checked in today.";
        }

        return $reminder === null
            ? 'No alerts. Check in whenever you like.'
            : 'No alerts. Check in before '.$reminder->format('g:i A').'.';
    }
}
