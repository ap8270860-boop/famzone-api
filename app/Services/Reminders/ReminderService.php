<?php

namespace App\Services\Reminders;

use App\Events\Reminders\ReminderAnswered;
use App\Events\Reminders\ReminderAssigned;
use App\Models\FamilyMember;
use App\Models\Reminder;
use App\Models\ReminderCategory;
use App\Models\ReminderOccurrence;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Social\NotificationService;
use App\Support\Reminders\Recurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Reminders: the catalogue, the rules, and what came of them.
 *
 * Three things are worth stating before reading any of it.
 *
 * **This service never makes a phone ring.** Not one line here schedules,
 * sounds or delivers anything. The device reads these rows and registers real
 * OS alarms against them, which is why a reminder fires on a plane, in a lift,
 * with the app force-closed and this server switched off. An alarm clock that
 * needs four bars of signal is not an alarm clock. The server owns the
 * definition; the phone owns the moment.
 *
 * **The future is computed and the past is stored.** A daily reminder is one
 * row forever, and today's occurrences are expanded from the rule on every
 * read — cheap, and correct the instant somebody edits the time. Rows appear
 * in reminder_occurrences only as occurrences settle, which is what keeps a
 * medicine history immutable: change tomorrow's dose to 9am and yesterday
 * still says 8am, because yesterday is a row rather than a calculation.
 *
 * **An assigned reminder does not ring until it is accepted.** Somebody else
 * putting an alarm on your phone is a request, not a fact.
 */
class ReminderService
{
    /** How far ahead the phone is told to schedule. */
    public const SCHEDULE_WINDOW_DAYS = 14;

    /**
     * The hard ceiling on what one sync hands back.
     *
     * iOS will hold 64 pending local notifications per app and silently drops
     * the rest — so the *client* budgets carefully across its reminders. This
     * is the server's own guard against a pathological rule, nothing more.
     */
    public const MAX_UPCOMING = 400;

    public function __construct(
        private readonly NotificationService $notifier,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | The catalogue
    |--------------------------------------------------------------------------
    */

    /**
     * Every category and its presets, in one request.
     *
     * Cached for an hour. This is seeded data that changes on a deploy and is
     * read on every open of the picker — the one thing in this service that is
     * genuinely the same for every account on the platform.
     *
     * @return array<string, mixed>
     */
    public function catalogue(): array
    {
        return cache()->remember('reminders.catalogue.v1', 3600, function (): array {
            $categories = ReminderCategory::query()
                ->active()
                ->with('templates')
                ->get();

            return [
                'categories' => $categories
                    ->map(fn (ReminderCategory $c) => [
                        'id' => $c->uuid,
                        'key' => $c->key,
                        'name' => $c->name,
                        'icon' => $c->icon,
                        'vibe' => $c->vibe,
                        'colour_from' => $c->colour_from,
                        'colour_to' => $c->colour_to,
                        'tagline' => $c->tagline,
                        'is_custom' => $c->is_custom,
                        'templates' => $c->templates
                            ->map(fn (ReminderTemplate $t) => [
                                'id' => $t->uuid,
                                'key' => $t->key,
                                'name' => $t->name,
                                'icon' => $t->icon,
                                'default_time' => $t->defaultTimeLabel(),
                                'default_repeat' => $t->default_repeat,
                                'default_weekday_mask' => $t->default_weekday_mask,
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),

                'ringtones' => Reminder::RINGTONES,
                'repeat_modes' => Recurrence::MODES,
                'max_active' => Reminder::MAX_ACTIVE_PER_USER,
                'schedule_window_days' => self::SCHEDULE_WINDOW_DAYS,
            ];
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * Everything one person can see, plus what their phone should schedule.
     *
     * One endpoint rather than three, because the client needs all of it on
     * every open and the three answers have to agree with each other. A list
     * that disagrees with the schedule is a reminder that shows on screen and
     * never rings.
     *
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        $now = $this->localNow($user);

        $reminders = Reminder::query()
            ->visibleTo($user->id)
            ->with(['category', 'owner', 'assignee'])
            ->orderBy('time_of_day')
            ->get();

        $mine = $reminders->filter(
            fn (Reminder $r) => $r->assignee_id === $user->id
        )->values();

        $setByMe = $reminders->filter(
            fn (Reminder $r) => $r->user_id === $user->id && $r->assignee_id !== $user->id
        )->values();

        return [
            'as_of' => $now->toIso8601String(),
            'timezone' => $now->timezoneName,

            // Mine to do.
            'reminders' => $mine
                ->map(fn (Reminder $r) => $this->present($r, $user))
                ->all(),

            // What I set for other people, which is a different screen and a
            // different question — "did they accept, are they doing it".
            'assigned_by_me' => $setByMe
                ->map(fn (Reminder $r) => $this->present($r, $user))
                ->all(),

            // Waiting on my answer. Pulled out rather than left for the client
            // to filter, so the badge is a number and not a scan.
            'pending_count' => $mine
                ->filter(fn (Reminder $r) => $r->isAwaitingAnswer())
                ->count(),

            'today' => $this->day($user, $now->toDateString()),

            /*
             | What the phone should register with the OS.
             |
             | Sent from here rather than computed on the device so the two
             | cannot drift: month-end clamping and the weekday mask are
             | fiddly enough that two implementations would eventually
             | disagree, and the one that disagrees silently is the one on the
             | phone.
             */
            'schedule' => $this->schedule($user, $now),
        ];
    }

    /**
     * The concrete moments a phone should ring, for the next fortnight.
     *
     * @return list<array<string, mixed>>
     */
    public function schedule(User $user, ?CarbonImmutable $now = null): array
    {
        $now = $now ?? $this->localNow($user);
        $until = $now->addDays(self::SCHEDULE_WINDOW_DAYS);

        $reminders = Reminder::query()
            ->ringingFor($user->id)
            ->with('category')
            ->get();

        $out = [];

        foreach ($reminders as $reminder) {
            foreach ($reminder->rule()->between($now, $until) as $at) {
                $out[] = [
                    'reminder_id' => $reminder->uuid,
                    'at' => $at->toIso8601String(),

                    /*
                     | The wall clock as well as the instant.
                     |
                     | The phone schedules against its own local clock, so
                     | handing it "2026-09-15 07:00" saves it converting an
                     | instant back into the zone it is about to schedule in —
                     | and a conversion that happens twice is a conversion
                     | that can disagree with itself.
                     */
                    'local' => $at->format('Y-m-d H:i'),
                    'title' => $reminder->title,
                    'body' => $reminder->note ?: $reminder->category?->name,
                    'ringtone' => $reminder->ringtone,
                    'vibrate' => $reminder->vibrate,
                    'snooze_minutes' => $reminder->snooze_minutes,
                ];
            }
        }

        usort($out, static fn ($a, $b) => strcmp($a['at'], $b['at']));

        return array_slice($out, 0, self::MAX_UPCOMING);
    }

    /**
     * One day, as the calendar draws it: what was due, and what came of it.
     *
     * @return array<string, mixed>
     */
    public function day(User $user, string $date): array
    {
        $zone = Recurrence::safeZone($user->timezone);
        $start = CarbonImmutable::parse($date, $zone)->startOfDay();
        $end = $start->endOfDay();

        $reminders = Reminder::query()
            ->where('assignee_id', $user->id)
            ->where('status', '!=', Reminder::STATUS_ARCHIVED)
            ->whereIn('assignment_status', [
                Reminder::ASSIGNMENT_SELF,
                Reminder::ASSIGNMENT_ACCEPTED,
            ])
            ->with('category')
            ->get()
            ->keyBy('id');

        // Every settled outcome for the day, in one query rather than one per
        // occurrence.
        $settled = ReminderOccurrence::query()
            ->forDay($user->id, $start->toDateString())
            ->get()
            ->keyBy(fn (ReminderOccurrence $o) => $o->reminder_id.'@'.$o->due_at->toIso8601String());

        $items = [];

        foreach ($reminders as $reminder) {
            foreach ($reminder->rule()->between($start, $end) as $at) {
                $key = $reminder->id.'@'.$at->utc()->toIso8601String();
                $row = $settled->get($key);

                $items[] = [
                    'reminder_id' => $reminder->uuid,
                    'occurrence_id' => $row?->uuid,
                    'title' => $reminder->title,
                    'icon' => $reminder->icon ?? $reminder->category?->icon,
                    'category' => $reminder->category?->key,
                    'colour_from' => $reminder->category?->colour_from,
                    'colour_to' => $reminder->category?->colour_to,
                    'at' => $at->toIso8601String(),
                    'time' => $at->format('H:i'),

                    /*
                     | No row means nothing has happened yet — which reads as
                     | "pending" today and as "missed" on a day that is over.
                     | The nightly close-out writes those missed rows so the
                     | score can be a simple aggregate, but the calendar must
                     | still be right in the hours before it runs.
                     */
                    'status' => $row?->status
                        ?? ($at->isPast() ? ReminderOccurrence::STATUS_MISSED : 'pending'),

                    'completed_at' => $row?->completed_at?->toIso8601String(),
                    'snoozed_until' => $row?->snoozed_until?->toIso8601String(),
                ];
            }
        }

        usort($items, static fn ($a, $b) => strcmp($a['at'], $b['at']));

        $counted = array_filter(
            $items,
            static fn ($i) => in_array($i['status'], ['done', 'missed'], true),
        );

        $done = array_filter($counted, static fn ($i) => $i['status'] === 'done');

        return [
            'date' => $start->toDateString(),
            'items' => array_values($items),
            'due' => count($items),
            'done' => count($done),
            'settled' => count($counted),

            // Null rather than 0% when nothing has come due yet. A fresh
            // morning showing "0%" reads as failure rather than as "not yet".
            'rate' => count($counted) === 0
                ? null
                : (int) round(count($done) / count($counted) * 100),
        ];
    }

    /**
     * A whole month, as the calendar grid draws it.
     *
     * One row per day with counts, not the full list of occurrences — a month
     * of a busy user is several hundred items and the grid draws a dot, not a
     * title. Tapping a square calls day() for the detail.
     *
     * Two queries regardless of how many days or reminders are involved: the
     * settled rows in one go, and the active rules once. Everything else is
     * arithmetic.
     *
     * @return array<string, mixed>
     */
    public function month(User $user, string $month): array
    {
        $zone = Recurrence::safeZone($user->timezone);

        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00', $zone);

        if ($start === false) {
            abort(422, 'Send the month as YYYY-MM.');
        }

        $start = $start->startOfMonth();
        $end = $start->endOfMonth();
        $today = CarbonImmutable::now($zone)->startOfDay();

        /*
         | Settled outcomes for the month, counted per day in PHP rather than
         | with a GROUP BY.
         |
         | The row count here is bounded by what one person actually did in a
         | month — hundreds at the very most — and counting them in memory
         | keeps this a plain indexed range scan instead of an aggregate the
         | query planner has to think about.
         */
        $settled = ReminderOccurrence::query()
            ->between($user->id, $start->toDateString(), $end->toDateString())
            ->get(['due_on', 'status'])
            ->groupBy(fn (ReminderOccurrence $o) => $o->due_on->toDateString());

        $reminders = Reminder::query()
            ->ringingFor($user->id)
            ->get();

        $days = [];
        $monthDone = 0;
        $monthCounted = 0;

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $rows = $settled->get($date);

            // How many the rules say were due, whether or not anything was
            // recorded. This is what makes an untouched past day read as
            // missed before the hourly close-out has caught up with it.
            $due = 0;

            foreach ($reminders as $reminder) {
                if ($reminder->rule()->occursOn($day)) {
                    $due++;
                }
            }

            $done = $rows?->where('status', ReminderOccurrence::STATUS_DONE)->count() ?? 0;
            $missed = $rows?->where('status', ReminderOccurrence::STATUS_MISSED)->count() ?? 0;
            $skipped = $rows?->where('status', ReminderOccurrence::STATUS_SKIPPED)->count() ?? 0;

            $counted = $done + $missed;

            $monthDone += $done;
            $monthCounted += $counted;

            $future = $day->greaterThan($today);

            $days[] = [
                'date' => $date,
                'day' => $day->day,

                /*
                 | ISO weekday, so the client can offset the first row without
                 | reimplementing calendar arithmetic — and without the
                 | Sunday-or-Monday ambiguity that Carbon's own dayOfWeek has
                 | moved on between versions.
                 */
                'weekday' => $day->dayOfWeekIso,

                'due' => $due,
                'done' => $done,
                'missed' => $missed,
                'skipped' => $skipped,

                'is_today' => $day->isSameDay($today),
                'is_future' => $future,

                /*
                 | What the square is coloured by. Computed here so the app and
                 | any dashboard cannot disagree about what a green dot means.
                 |
                 |   none    nothing was due
                 |   future  due, but has not happened yet
                 |   perfect everything due was done
                 |   partial some done, some not
                 |   missed  due, and nothing was done
                 |   open    due today and still in play
                 */
                'state' => match (true) {
                    $due === 0 => 'none',
                    $future => 'future',
                    $counted === 0 => $day->isSameDay($today) ? 'open' : 'missed',
                    $done === $counted => 'perfect',
                    $done === 0 => 'missed',
                    default => 'partial',
                },
            ];
        }

        return [
            'month' => $start->format('Y-m'),
            'label' => $start->format('F Y'),
            'starts_weekday' => $start->dayOfWeekIso,
            'days_in_month' => $start->daysInMonth,

            // So the client can put arrows on the header without guessing
            // about month lengths or year boundaries.
            'previous' => $start->subMonth()->format('Y-m'),
            'next' => $start->addMonth()->format('Y-m'),

            'done' => $monthDone,
            'total' => $monthCounted,
            'rate' => $monthCounted === 0
                ? null
                : (int) round($monthDone / $monthCounted * 100),

            'days' => $days,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Writing
    |--------------------------------------------------------------------------
    */

    /**
     * Create or update a reminder.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(User $user, array $input, ?string $uuid = null): array
    {
        $reminder = $uuid === null
            ? new Reminder()
            : $this->findEditable($user, $uuid);

        $category = $this->resolveCategory($input['category_id'] ?? null, $reminder);
        $template = $this->resolveTemplate($input['template_id'] ?? null, $category);

        $assignee = $this->resolveAssignee($user, $input['assignee_id'] ?? null);

        if ($uuid === null) {
            $active = Reminder::query()
                ->where('user_id', $user->id)
                ->where('status', Reminder::STATUS_ACTIVE)
                ->count();

            abort_if(
                $active >= Reminder::MAX_ACTIVE_PER_USER,
                422,
                'You already have '.Reminder::MAX_ACTIVE_PER_USER.' reminders. '
                    .'Pause or remove one first.',
            );
        }

        $wasAssignedTo = $reminder->exists ? $reminder->assignee_id : null;

        $reminder->fill([
            'title' => trim((string) ($input['title'] ?? $template?->name ?? 'Reminder')),
            'note' => $input['note'] ?? null,
            'icon' => $input['icon'] ?? $template?->icon,
            'repeat_mode' => $input['repeat_mode'] ?? Recurrence::DAILY,
            'time_of_day' => $this->normaliseTime($input['time_of_day'] ?? '09:00'),
            'weekday_mask' => (int) ($input['weekday_mask'] ?? 0),
            'day_of_month' => $input['day_of_month'] ?? null,
            'starts_on' => $input['starts_on'] ?? CarbonImmutable::now(
                Recurrence::safeZone($assignee->timezone)
            )->toDateString(),
            'ends_on' => $input['ends_on'] ?? null,
            'timezone' => Recurrence::safeZone($assignee->timezone),
            'ringtone' => in_array($input['ringtone'] ?? '', Reminder::RINGTONES, true)
                ? $input['ringtone']
                : 'default',
            'vibrate' => (bool) ($input['vibrate'] ?? true),
            'snooze_minutes' => (int) ($input['snooze_minutes'] ?? 10),
            'status' => $input['status'] ?? Reminder::STATUS_ACTIVE,
            'meta' => $input['meta'] ?? null,
        ]);

        /*
         | A `once` reminder ends on the day it happens.
         |
         | Set here rather than left null so "is this finished" is one
         | comparison for every mode, and so a one-off that has passed drops
         | out of the schedule on its own rather than needing a special case
         | in the expander.
         */
        if ($reminder->repeat_mode === Recurrence::ONCE) {
            $reminder->ends_on = $reminder->starts_on;
        }

        $reminder->user_id = $reminder->exists ? $reminder->user_id : $user->id;
        $reminder->assignee_id = $assignee->id;
        $reminder->reminder_category_id = $category->id;
        $reminder->reminder_template_id = $template?->id;

        /*
         | Reassigning restarts the conversation.
         |
         | Moving a reminder from one person to another must not carry the
         | first person's acceptance across — the new assignee has not agreed
         | to anything, and inheriting "accepted" would put a ringing alarm on
         | their phone without asking.
         */
        $changedHands = $wasAssignedTo !== null && $wasAssignedTo !== $assignee->id;

        if (! $reminder->exists || $changedHands) {
            $reminder->assignment_status = $assignee->id === $user->id
                ? Reminder::ASSIGNMENT_SELF
                : Reminder::ASSIGNMENT_PENDING;

            $reminder->assignment_responded_at = null;
        }

        $reminder->save();

        $fresh = $reminder->fresh(['category', 'owner', 'assignee']);

        // Ask, after the row is committed and only when there is somebody to
        // ask. Guarded: a failed notification must not fail the save.
        if ($fresh !== null && $fresh->isAwaitingAnswer()) {
            $this->askAssignee($fresh);
        }

        return $this->present($fresh ?? $reminder, $user);
    }

    /**
     * Answer a reminder somebody set for you.
     *
     * @return array<string, mixed>
     */
    public function respondToAssignment(User $user, string $uuid, bool $accept): array
    {
        $reminder = Reminder::query()
            ->where('uuid', $uuid)
            ->where('assignee_id', $user->id)
            ->with(['category', 'owner', 'assignee'])
            ->first();

        abort_if($reminder === null, 404, 'That reminder does not exist.');

        // Already answered. Idempotent rather than a 422 — a second tap from a
        // slow phone is not a failure.
        if (! $reminder->isAwaitingAnswer()) {
            return [
                'changed' => false,
                'message' => 'You have already answered this one.',
                'reminder' => $this->present($reminder, $user),
            ];
        }

        $reminder->forceFill([
            'assignment_status' => $accept
                ? Reminder::ASSIGNMENT_ACCEPTED
                : Reminder::ASSIGNMENT_DECLINED,
            'assignment_responded_at' => now(),

            // A declined reminder is paused as well as declined, so nothing
            // downstream has to remember to check two columns before ringing.
            'status' => $accept ? $reminder->status : Reminder::STATUS_PAUSED,
        ])->save();

        $fresh = $reminder->fresh(['category', 'owner', 'assignee']);

        $this->tellOwner($fresh ?? $reminder, $user, $accept);

        return [
            'changed' => true,
            'message' => $accept
                ? 'Added to your reminders.'
                : 'Declined. They have been told.',
            'reminder' => $this->present($fresh ?? $reminder, $user),
        ];
    }

    /**
     * Mark one occurrence done, snoozed or skipped.
     *
     * `due_at` identifies which occurrence — not an id, because the occurrence
     * usually does not exist yet. That is the point of computing the future:
     * the row is created by this call, at the moment somebody has an opinion
     * about it.
     *
     * @return array<string, mixed>
     */
    public function settle(
        User $user,
        string $uuid,
        string $dueAt,
        string $status,
        ?string $dueLocal = null,
    ): array {
        $reminder = Reminder::query()
            ->where('uuid', $uuid)
            ->where('assignee_id', $user->id)
            ->with('category')
            ->first();

        abort_if($reminder === null, 404, 'That reminder does not exist.');

        abort_unless(
            in_array($status, [
                ReminderOccurrence::STATUS_DONE,
                ReminderOccurrence::STATUS_SNOOZED,
                ReminderOccurrence::STATUS_SKIPPED,
            ], true),
            422,
            'That is not something you can do to a reminder.',
        );

        $zone = Recurrence::safeZone($reminder->timezone);

        /*
         | The wall clock the phone actually rang at, when it sends one.
         |
         | An instant alone is not enough to identify an occurrence, because it
         | only means the same thing to both ends if they agree on the zone —
         | and they do not always. A reminder saved before `timezone` was being
         | filled in says UTC while the phone is in IST, so a Done pressed at
         | 08:03 arrives as 02:33Z, the check below compares 02:33 against
         | 08:03 and rejects it. The answer is then dropped as a 4xx and the
         | nightly close-out writes the occurrence off as missed — the user
         | pressed Done and watched it turn into a miss.
         |
         | `due_local` is the same "wall clock as well as the instant" that
         | schedule() already sends in the other direction, and it makes the
         | round trip agree no matter what the stored zone says.
         */
        $at = $dueLocal !== null && $dueLocal !== ''
            ? CarbonImmutable::parse($dueLocal, $zone)
            : CarbonImmutable::parse($dueAt)->setTimezone($zone);

        /*
         | The moment has to be one this rule actually produces.
         |
         | Without this check a client could settle arbitrary timestamps and
         | inflate an adherence score by inventing occurrences that were never
         | due. Checked against the rule rather than against a stored row,
         | because there is no stored row yet.
         */
        abort_unless(
            $reminder->rule()->occursOn($at)
                && $at->format('H:i') === substr((string) $reminder->time_of_day, 0, 5),
            422,
            'That is not a time this reminder was due.',
        );

        $snoozeUntil = $status === ReminderOccurrence::STATUS_SNOOZED
            ? now()->addMinutes(max(1, $reminder->snooze_minutes))
            : null;

        $occurrence = ReminderOccurrence::updateOrCreate(
            [
                'reminder_id' => $reminder->id,
                'due_at' => $at->utc(),
            ],
            [
                'user_id' => $user->id,
                'due_on' => $at->toDateString(),
                'status' => $status,
                'completed_at' => $status === ReminderOccurrence::STATUS_DONE
                    ? now()
                    : null,
                'snoozed_until' => $snoozeUntil,
            ],
        );

        /*
         | Tell whoever set it, but only for a completion and only when it was
         | somebody else's reminder.
         |
         | Not for snoozes and not for skips: the person who set a medicine
         | reminder wants to know it was taken, and a stream of "snoozed"
         | notices would train them to ignore the one that matters.
         */
        if ($status === ReminderOccurrence::STATUS_DONE && $reminder->isAssigned()) {
            $this->tellOwnerDone($reminder, $user);
        }

        return [
            'occurrence' => [
                'id' => $occurrence->uuid,
                'reminder_id' => $reminder->uuid,
                'due_at' => $at->toIso8601String(),
                'status' => $occurrence->status,
                'snoozed_until' => $occurrence->snoozed_until?->toIso8601String(),
            ],
            'today' => $this->day($user, $this->localNow($user)->toDateString()),
        ];
    }

    public function destroy(User $user, string $uuid): void
    {
        $reminder = $this->findEditable($user, $uuid);

        /*
         | Archived, not deleted.
         |
         | The occurrences hanging off it are somebody's medicine history, and
         | cascading them away because a reminder was tidied up is a data loss
         | nobody asks for and nobody can undo.
         */
        $reminder->forceFill(['status' => Reminder::STATUS_ARCHIVED])->save();
    }

    /*
    |--------------------------------------------------------------------------
    | The nightly close-out
    |--------------------------------------------------------------------------
    */

    /**
     * Write a `missed` row for everything that came due and was never
     * answered.
     *
     * This is what turns the score from a walk over recurrence rules into a
     * single aggregate, and — more importantly — what freezes history. Once a
     * day is closed, editing the reminder cannot change what it says.
     *
     * Idempotent: the unique index on (reminder_id, due_at) means running it
     * twice writes nothing the second time.
     *
     * @return array{closed: int, scanned: int}
     */
    public function closeOut(?CarbonImmutable $upTo = null): array
    {
        $cutoff = $upTo ?? CarbonImmutable::now()->subHours(6);

        $reminders = Reminder::query()
            ->where('status', '!=', Reminder::STATUS_ARCHIVED)
            ->whereIn('assignment_status', [
                Reminder::ASSIGNMENT_SELF,
                Reminder::ASSIGNMENT_ACCEPTED,
            ])
            ->get();

        $closed = 0;

        foreach ($reminders as $reminder) {
            $zone = Recurrence::safeZone($reminder->timezone);

            /*
             | A two-day window, not "everything since the beginning".
             |
             | The job runs nightly, so two days is ample slack for a missed
             | run — and it bounds the work regardless of how long a reminder
             | has existed. A machine that was off for a week loses the middle
             | of it, which is the right trade against a job that walks years
             | of rules every night forever.
             */
            $from = $cutoff->setTimezone($zone)->subDays(2)->startOfDay();
            $to = $cutoff->setTimezone($zone);

            foreach ($reminder->rule()->between($from, $to) as $at) {
                $exists = ReminderOccurrence::query()
                    ->where('reminder_id', $reminder->id)
                    ->where('due_at', $at->utc())
                    ->exists();

                if ($exists) {
                    continue;
                }

                try {
                    ReminderOccurrence::create([
                        'reminder_id' => $reminder->id,
                        'user_id' => $reminder->assignee_id,
                        'due_on' => $at->toDateString(),
                        'due_at' => $at->utc(),
                        'status' => ReminderOccurrence::STATUS_MISSED,
                    ]);

                    $closed++;
                } catch (\Throwable $e) {
                    // The unique index doing its job against a tap that landed
                    // in the same instant. Nothing to fix.
                    Log::debug('reminder close-out skipped a duplicate', [
                        'reminder' => $reminder->uuid,
                        'due_at' => $at->toIso8601String(),
                    ]);
                }
            }
        }

        return ['closed' => $closed, 'scanned' => $reminders->count()];
    }

    /*
    |--------------------------------------------------------------------------
    | The score
    |--------------------------------------------------------------------------
    */

    /**
     * Adherence over a window, plus the streak.
     *
     * Skipped occurrences are excluded from both halves of the fraction on
     * purpose — see ReminderOccurrence::COUNTED_STATUSES.
     *
     * @return array<string, mixed>
     */
    public function score(User $user, int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $now = $this->localNow($user);
        $from = $now->subDays($days - 1)->startOfDay();

        $rows = ReminderOccurrence::query()
            ->between($user->id, $from->toDateString(), $now->toDateString())
            ->whereIn('status', ReminderOccurrence::COUNTED_STATUSES)
            ->get(['due_on', 'status']);

        $done = $rows->where('status', ReminderOccurrence::STATUS_DONE)->count();
        $total = $rows->count();

        /*
         | A day counts towards the streak when everything that came due on it
         | was done. A perfect run of one reminder is still a perfect day —
         | the streak is about answering what you were asked, not about how
         | much you asked of yourself.
         */
        $byDay = $rows->groupBy(fn ($r) => $r->due_on->toDateString());

        $streak = 0;
        $cursor = $now->startOfDay();

        while ($streak < $days) {
            $day = $byDay->get($cursor->toDateString());

            // A day with nothing due neither breaks a streak nor extends it.
            if ($day === null) {
                if ($cursor->isSameDay($now)) {
                    $cursor = $cursor->subDay();

                    continue;
                }

                break;
            }

            if ($day->contains(fn ($r) => $r->status !== ReminderOccurrence::STATUS_DONE)) {
                break;
            }

            $streak++;
            $cursor = $cursor->subDay();
        }

        return [
            'days' => $days,
            'from' => $from->toDateString(),
            'to' => $now->toDateString(),
            'done' => $done,
            'total' => $total,
            'rate' => $total === 0 ? null : (int) round($done / $total * 100),
            'streak' => $streak,
            'headline' => $total === 0
                ? 'Nothing due yet'
                : $done.' of '.$total.' done',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Telling people
    |--------------------------------------------------------------------------
    */

    private function askAssignee(Reminder $reminder): void
    {
        $owner = $reminder->owner;
        $assignee = $reminder->assignee;

        if ($owner === null || $assignee === null) {
            return;
        }

        try {
            $this->notifier->push(
                to: $assignee,
                actor: $owner,
                type: UserNotification::REMINDER_ASSIGNED,
                subject: $reminder,
                data: [
                    'message' => $this->firstName($owner).' set a reminder for you: '
                        .$reminder->title.'.',
                    'reminder_id' => $reminder->uuid,
                ],
            );
        } catch (\Throwable $e) {
            Log::error('reminder assignment notification failed', [
                'reminder' => $reminder->uuid,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            ReminderAssigned::dispatch($reminder, $owner, $assignee);
        } catch (\Throwable $e) {
            Log::error('reminder assignment broadcast failed', [
                'reminder' => $reminder->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function tellOwner(Reminder $reminder, User $assignee, bool $accepted): void
    {
        $owner = $reminder->owner;

        if ($owner === null || $owner->id === $assignee->id) {
            return;
        }

        try {
            $this->notifier->push(
                to: $owner,
                actor: $assignee,
                type: $accepted
                    ? UserNotification::REMINDER_ACCEPTED
                    : UserNotification::REMINDER_DECLINED,
                subject: $reminder,
                data: [
                    'message' => $this->firstName($assignee)
                        .($accepted ? ' accepted ' : ' declined ')
                        .'your reminder: '.$reminder->title.'.',
                    'reminder_id' => $reminder->uuid,
                ],
            );
        } catch (\Throwable $e) {
            Log::error('reminder answer notification failed', [
                'reminder' => $reminder->uuid,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            ReminderAnswered::dispatch($reminder, $owner, $assignee, $accepted);
        } catch (\Throwable $e) {
            Log::error('reminder answer broadcast failed', [
                'reminder' => $reminder->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * "Dad took his tablet."
     *
     * The payoff for the whole assignment feature, and the reason somebody
     * sets a reminder on another person's phone in the first place.
     */
    private function tellOwnerDone(Reminder $reminder, User $assignee): void
    {
        $owner = $reminder->owner;

        if ($owner === null || $owner->id === $assignee->id) {
            return;
        }

        try {
            $this->notifier->push(
                to: $owner,
                actor: $assignee,
                type: UserNotification::REMINDER_DONE,
                subject: $reminder,
                data: [
                    'message' => $this->firstName($assignee).' did it: '
                        .$reminder->title.'.',
                    'reminder_id' => $reminder->uuid,
                ],
            );
        } catch (\Throwable $e) {
            Log::error('reminder completion notification failed', [
                'reminder' => $reminder->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function findEditable(User $user, string $uuid): Reminder
    {
        $reminder = Reminder::query()
            ->where('uuid', $uuid)
            ->where(function ($q) use ($user) {
                // Either end may edit. The person it rings for has to be able
                // to change its time or turn it off without asking permission
                // — an alarm you cannot silence on your own phone is not a
                // reminder, it is somebody else's control panel.
                $q->where('user_id', $user->id)->orWhere('assignee_id', $user->id);
            })
            ->first();

        abort_if($reminder === null, 404, 'That reminder does not exist.');

        return $reminder;
    }

    private function resolveCategory(?string $uuid, Reminder $reminder): ReminderCategory
    {
        if ($uuid !== null) {
            $category = ReminderCategory::where('uuid', $uuid)->first();

            if ($category !== null) {
                return $category;
            }
        }

        if ($reminder->exists && $reminder->category !== null) {
            return $reminder->category;
        }

        $fallback = ReminderCategory::where('key', ReminderCategory::KEY_CUSTOM)->first();

        abort_if($fallback === null, 500, 'The reminder catalogue has not been seeded.');

        return $fallback;
    }

    private function resolveTemplate(?string $uuid, ReminderCategory $category): ?ReminderTemplate
    {
        if ($uuid === null) {
            return null;
        }

        return ReminderTemplate::query()
            ->where('uuid', $uuid)
            ->where('reminder_category_id', $category->id)
            ->first();
    }

    /**
     * Who this rings for.
     *
     * Anyone not in the family falls back to the creator rather than raising.
     * A severed family link between opening the editor and pressing save
     * should produce a reminder on your own phone, not an error about a
     * relationship you have already ended.
     */
    private function resolveAssignee(User $user, ?string $uuid): User
    {
        if ($uuid === null || $uuid === $user->uuid) {
            return $user;
        }

        $family = $this->familyOf($user)->firstWhere('uuid', $uuid);

        return $family ?? $user;
    }

    /**
     * @return Collection<int, User>
     */
    private function familyOf(User $user): Collection
    {
        return FamilyMember::query()
            ->accepted()
            ->involving($user->id)
            ->with(['owner', 'member'])
            ->get()
            ->map(fn (FamilyMember $link) => $link->counterpartFor($user))
            ->filter()
            ->unique('id')
            ->values();
    }

    /** "7:5" and "07:05:00" both mean the same thing; the column wants one. */
    private function normaliseTime(string $value): string
    {
        $parts = explode(':', trim($value));

        $hour = max(0, min(23, (int) ($parts[0] ?? 0)));
        $minute = max(0, min(59, (int) ($parts[1] ?? 0)));

        return sprintf('%02d:%02d:00', $hour, $minute);
    }

    private function localNow(User $user): CarbonImmutable
    {
        return CarbonImmutable::now(Recurrence::safeZone($user->timezone));
    }

    private function firstName(User $user): string
    {
        $name = trim((string) $user->name);

        if ($name === '') {
            return 'Someone';
        }

        return (preg_split('/\s+/', $name) ?: [$name])[0];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Reminder $reminder, ?User $viewer = null): array
    {
        $rule = $reminder->rule();
        $next = $reminder->shouldRing()
            ? $rule->nextAfter(CarbonImmutable::now($rule->timezone))
            : null;

        $owner = $reminder->owner;
        $assignee = $reminder->assignee;

        return [
            'id' => $reminder->uuid,
            'title' => $reminder->title,
            'note' => $reminder->note,
            'icon' => $reminder->icon ?? $reminder->category?->icon,

            'category' => $reminder->category === null ? null : [
                'id' => $reminder->category->uuid,
                'key' => $reminder->category->key,
                'name' => $reminder->category->name,
                'icon' => $reminder->category->icon,
                'vibe' => $reminder->category->vibe,
                'colour_from' => $reminder->category->colour_from,
                'colour_to' => $reminder->category->colour_to,
            ],

            'repeat_mode' => $reminder->repeat_mode,
            'time' => substr((string) $reminder->time_of_day, 0, 5),
            'weekday_mask' => $reminder->weekday_mask,
            'day_of_month' => $reminder->day_of_month,
            'starts_on' => $reminder->starts_on?->toDateString(),
            'ends_on' => $reminder->ends_on?->toDateString(),
            'timezone' => $reminder->timezone,

            // The sentence, written once here so the app and any dashboard
            // cannot describe the same rule differently.
            'schedule_label' => $rule->describe(),
            'next_at' => $next?->toIso8601String(),

            'ringtone' => $reminder->ringtone,
            'vibrate' => $reminder->vibrate,
            'snooze_minutes' => $reminder->snooze_minutes,

            'status' => $reminder->status,
            'should_ring' => $reminder->shouldRing(),

            'assignment' => [
                'status' => $reminder->assignment_status,
                'is_assigned' => $reminder->isAssigned(),
                'awaiting' => $reminder->isAwaitingAnswer(),

                // Whether *this* viewer is the one who has to answer.
                'mine_to_answer' => $viewer !== null
                    && $reminder->isAwaitingAnswer()
                    && $reminder->assignee_id === $viewer->id,

                'set_by' => $owner === null ? null : [
                    'id' => $owner->uuid,
                    'name' => $owner->name,
                    'avatar_url' => $owner->avatar_url,
                ],
                'for' => $assignee === null ? null : [
                    'id' => $assignee->uuid,
                    'name' => $assignee->name,
                    'avatar_url' => $assignee->avatar_url,
                ],
            ],

            'meta' => $reminder->meta,
            'photo_url' => $reminder->photo_path,
        ];
    }
}
