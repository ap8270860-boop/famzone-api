<?php

namespace App\Services\Safety;

use App\Events\Safety\CheckInAcknowledged;
use App\Events\Safety\CheckInRequested;
use App\Models\CheckInContact;
use App\Models\CheckInEscalation;
use App\Models\CheckInEscalationStep;
use App\Models\FamilyMember;
use App\Models\SafetyCheckIn;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Social\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The check-in notification chain.
 *
 * Checking in used to be a private act: a row in a table and a tick on a card,
 * which told the person who tapped it something they already knew. This turns
 * it into a message — but a message addressed to one person at a time, in an
 * order the sender chose, which stops the moment somebody answers.
 *
 * Three ideas hold the whole thing up.
 *
 * **The chain is a snapshot.** The ordered list of contacts is a preference and
 * can be edited whenever the owner likes; a run copies it at the moment it
 * starts and never looks at it again. Otherwise rearranging your contacts on
 * Tuesday would silently rewrite what happened on Monday, and the progress bar
 * on a live chain would reshuffle under the reader's finger.
 *
 * **One fact, derived everywhere.** Whether Kulsoom can still accept is the
 * status of her step, read at the moment the question is asked. It is not
 * copied onto the notification, not cached on the escalation, and not sent
 * down as a boolean that could go stale. Accept from the banner and the button
 * in the feed is gone, because there was only ever one place the answer lived.
 *
 * **The timer is a column, not a job.** `next_escalation_at` says when this run
 * next needs attention; a command sweeps for overdue rows once a minute. A
 * delayed queue job would be more precise and strictly worse: it cannot be
 * cancelled when somebody accepts early, it is lost if the worker is restarted,
 * and a server that was down for two hours comes back having quietly dropped
 * every timer it was holding. This design comes back and finds them all,
 * overdue, and works through them.
 *
 * Everything that reaches the outside world — websocket frames, feed rows — is
 * best-effort and happens after the transaction commits. A Reverb that is down
 * must degrade to "they find out when they open the app", never to a check-in
 * that failed to record.
 */
class CheckInEscalationService
{
    public function __construct(
        private readonly NotificationService $notifier,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | The contact list
    |--------------------------------------------------------------------------
    */

    /**
     * The owner's ordered list, with each person resolved.
     *
     * @return array<string, mixed>
     */
    public function contacts(User $owner): array
    {
        $rows = CheckInContact::query()
            ->where('user_id', $owner->id)
            ->with('contact')
            ->ordered()
            ->get();

        return [
            'configured' => $rows->isNotEmpty(),
            'max' => CheckInContact::MAX_CONTACTS,
            'timeout_minutes' => CheckInEscalation::DEFAULT_TIMEOUT_MINUTES,
            'contacts' => $rows
                ->filter(fn (CheckInContact $row) => $row->contact !== null)
                ->values()
                ->map(fn (CheckInContact $row) => [
                    'position' => $row->position,
                    ...$this->person($row->contact),
                ])
                ->all(),

            // Everybody who could be added, so the picker is one request.
            'available' => $this->familyOf($owner)
                ->map(fn (User $person) => $this->person($person))
                ->values()
                ->all(),
        ];
    }

    /**
     * Replace the list wholesale.
     *
     * Wholesale rather than patched, deliberately. A reorder is a permutation
     * and there is no honest way to express one as a series of single-row
     * edits without passing through states where two people share a position
     * — so the API takes the list the user is looking at, in the order they
     * arranged it, and the old one is deleted in the same transaction.
     *
     * Unknown ids and people who are not accepted family are dropped rather
     * than rejected. The client sends what it was showing; if a family link
     * was severed on another device between the sheet opening and Save being
     * pressed, the right outcome is a list without that person, not an error
     * dialog about a relationship the user has already ended.
     *
     * @param  list<string>  $contactUuids  In notification order.
     * @return array<string, mixed>
     */
    public function saveContacts(User $owner, array $contactUuids): array
    {
        $family = $this->familyOf($owner)->keyBy('uuid');

        $ordered = collect($contactUuids)
            ->filter(fn ($uuid) => is_string($uuid) && $uuid !== '')
            ->unique()
            ->map(fn (string $uuid) => $family->get($uuid))
            ->filter()
            ->take(CheckInContact::MAX_CONTACTS)
            ->values();

        DB::transaction(function () use ($owner, $ordered) {
            CheckInContact::where('user_id', $owner->id)->delete();

            foreach ($ordered as $index => $person) {
                $row = new CheckInContact(['position' => $index + 1]);
                $row->user_id = $owner->id;
                $row->contact_id = $person->id;
                $row->save();
            }
        });

        return $this->contacts($owner);
    }

    /*
    |--------------------------------------------------------------------------
    | Running a chain
    |--------------------------------------------------------------------------
    */

    /**
     * Start the chain for a check-in that has just been recorded.
     *
     * Returns null when there is nobody to tell, which is not a failure — the
     * check-in itself stands, and a person with an empty contact list has
     * simply chosen a private check-in.
     *
     * Safe to call twice for the same check-in: the unique index on
     * safety_check_in_id turns a duplicate into a no-op that returns the chain
     * already running, rather than a second round of notifications to the same
     * family for the same day.
     */
    public function begin(User $owner, SafetyCheckIn $checkIn): ?CheckInEscalation
    {
        $existing = CheckInEscalation::where('safety_check_in_id', $checkIn->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $contacts = CheckInContact::query()
            ->where('user_id', $owner->id)
            ->ordered()
            ->get();

        if ($contacts->isEmpty()) {
            return null;
        }

        $escalation = DB::transaction(function () use ($owner, $checkIn, $contacts) {
            $escalation = new CheckInEscalation([
                'status' => CheckInEscalation::STATUS_PENDING,
                'current_step' => 0,
                'total_steps' => $contacts->count(),
                'step_timeout_minutes' => CheckInEscalation::DEFAULT_TIMEOUT_MINUTES,
                'started_at' => now(),
            ]);

            $escalation->user_id = $owner->id;
            $escalation->safety_check_in_id = $checkIn->id;
            $escalation->save();

            foreach ($contacts as $index => $contact) {
                $step = new CheckInEscalationStep([
                    'position' => $index + 1,
                    'status' => CheckInEscalationStep::STATUS_WAITING,
                ]);

                $step->check_in_escalation_id = $escalation->id;
                $step->contact_id = $contact->contact_id;
                $step->save();
            }

            return $escalation;
        });

        $this->moveOn($escalation, closeCurrentWith: null);

        return $escalation->fresh();
    }

    /**
     * Somebody has answered.
     *
     * Idempotent in every direction. Answering a step that has already closed
     * returns the current state with a sentence explaining it rather than an
     * error: by the time a slow phone's Accept arrives, the chain may well
     * have moved on, and "Vinod already confirmed" is the true and useful
     * thing to say — not a 422.
     *
     * @return array<string, mixed>
     */
    public function respond(User $contact, string $stepUuid, bool $accept): array
    {
        $step = CheckInEscalationStep::query()
            ->where('uuid', $stepUuid)
            ->where('contact_id', $contact->id)
            ->with(['escalation.user', 'escalation.steps.contact'])
            ->first();

        abort_if($step === null, 404, 'That request does not exist.');

        $escalation = $step->escalation;

        abort_if($escalation === null, 404, 'That request does not exist.');

        // Already answered, skipped, or expired. Say what happened.
        if (! $step->isAwaitingResponse()) {
            return [
                'changed' => false,
                'message' => $this->settledMessage($escalation, $step),
                'chain' => $this->present($escalation->fresh(['steps.contact', 'acknowledgedBy'])),
            ];
        }

        if (! $accept) {
            /*
             | Declining is not a refusal to care — it is "I cannot vouch for
             | this, ask the next person". So it closes this step and moves the
             | request along immediately rather than waiting out the timer.
             */
            $this->moveOn($escalation, closeCurrentWith: CheckInEscalationStep::STATUS_REJECTED);

            // The question has been answered, so its feed row stops being
            // unread — even though the answer was "ask somebody else".
            $this->closeNotification($step);

            $fresh = $escalation->fresh(['steps.contact', 'acknowledgedBy']);

            return [
                'changed' => true,
                'message' => 'Passed on to the next person.',
                'chain' => $this->present($fresh),
            ];
        }

        $owner = $escalation->user;

        DB::transaction(function () use ($escalation, $step, $contact) {
            /*
             | Lock and re-read before deciding.
             |
             | Two people can be looking at the same chain — the current holder
             | and the sweep about to expire them. Whoever takes the lock first
             | wins, and the loser finds the run already settled and does
             | nothing.
             */
            $locked = CheckInEscalation::whereKey($escalation->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->isSettled()) {
                return;
            }

            $rows = CheckInEscalationStep::where('check_in_escalation_id', $locked->id)
                ->where('uuid', $step->uuid)
                ->awaiting()
                ->update([
                    'status' => CheckInEscalationStep::STATUS_ACCEPTED,
                    'responded_at' => now(),
                    'updated_at' => now(),
                ]);

            // Somebody else closed this step between the read and the lock.
            if ($rows === 0) {
                return;
            }

            /*
             | Everybody still waiting is spared.
             |
             | This is the line the whole feature is for: once one person has
             | confirmed, the rest are never told. Marked skipped rather than
             | deleted so the chain can still be drawn honestly afterwards —
             | "Vinod and Devrat were never asked" is part of the record.
             */
            CheckInEscalationStep::where('check_in_escalation_id', $locked->id)
                ->where('status', CheckInEscalationStep::STATUS_WAITING)
                ->update([
                    'status' => CheckInEscalationStep::STATUS_SKIPPED,
                    'updated_at' => now(),
                ]);

            $locked->forceFill([
                'status' => CheckInEscalation::STATUS_ACKNOWLEDGED,
                'acknowledged_by_id' => $contact->id,
                'acknowledged_at' => now(),
                'completed_at' => now(),

                // Nothing left to wait for. Nulling this is what takes the run
                // out of the sweep's sight.
                'next_escalation_at' => null,
            ])->save();
        });

        $fresh = $escalation->fresh(['steps.contact', 'acknowledgedBy']);

        // The feed row that asked the question has been answered.
        $this->closeNotification($step);

        if ($fresh !== null && $fresh->isAcknowledged() && $owner !== null) {
            $this->tellOwner($owner, $contact, $fresh);
        }

        return [
            'changed' => true,
            'message' => $owner === null
                ? 'Thank you.'
                : 'Thanks — '.$this->firstName($owner).' knows you have seen it.',
            'chain' => $this->present($fresh),
        ];
    }

    /**
     * Every chain currently waiting on this person.
     *
     * Drives the badge on the recipient's side without loading the whole feed.
     *
     * @return array<string, mixed>
     */
    public function awaiting(User $contact): array
    {
        $steps = CheckInEscalationStep::query()
            ->where('contact_id', $contact->id)
            ->awaiting()
            ->with(['escalation.user', 'escalation.steps.contact', 'escalation.acknowledgedBy'])
            ->orderByDesc('notified_at')
            ->limit(20)
            ->get()
            ->filter(fn (CheckInEscalationStep $step) => $step->escalation?->isPending() === true);

        return [
            'count' => $steps->count(),
            'requests' => $steps
                ->map(fn (CheckInEscalationStep $step) => $this->request($step))
                ->values()
                ->all(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | The timer
    |--------------------------------------------------------------------------
    */

    /**
     * Move every overdue chain along one place.
     *
     * Called once a minute by check-ins:escalate. The limit is a safety valve
     * rather than a throttle: a normal tick has a handful of rows, and a tick
     * that finds two hundred means something was down for a long time — in
     * which case working through them in batches a minute apart is exactly
     * the right behaviour.
     *
     * One failure never stops the sweep. A chain whose broadcast throws still
     * has its step advanced in the database, and the next tick carries on with
     * everybody else.
     *
     * @return array{swept: int, failed: int}
     */
    public function sweep(int $limit = 200): array
    {
        $due = CheckInEscalation::query()
            ->due()
            ->orderBy('next_escalation_at')
            ->limit(max(1, $limit))
            ->get();

        $swept = 0;
        $failed = 0;

        foreach ($due as $escalation) {
            try {
                $this->moveOn(
                    $escalation,
                    closeCurrentWith: CheckInEscalationStep::STATUS_EXPIRED,
                    onlyIfDue: true,
                );

                $swept++;
            } catch (\Throwable $e) {
                $failed++;

                Log::error('check-in escalation sweep failed', [
                    'escalation' => $escalation->uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['swept' => $swept, 'failed' => $failed];
    }

    /*
    |--------------------------------------------------------------------------
    | The engine
    |--------------------------------------------------------------------------
    */

    /**
     * Close the current step and hand the request to the next person.
     *
     * The single place a chain ever changes hands. Starting a run, a decline
     * and a timeout all come through here and differ only in what they close
     * the outgoing step with — which is why there is exactly one copy of the
     * "have we run out of people" rule.
     *
     * @param  string|null  $closeCurrentWith  Null when starting: there is no
     *                                         outgoing step to close.
     * @param  bool  $onlyIfDue  Set by the sweep. Makes the lock re-check the
     *                           deadline, so two overlapping ticks cannot
     *                           advance the same chain twice.
     */
    private function moveOn(
        CheckInEscalation $escalation,
        ?string $closeCurrentWith,
        bool $onlyIfDue = false,
    ): void {
        /** @var CheckInEscalationStep|null $notify */
        $notify = DB::transaction(function () use ($escalation, $closeCurrentWith, $onlyIfDue) {
            $locked = CheckInEscalation::whereKey($escalation->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->isSettled()) {
                return null;
            }

            /*
             | The sweep's second look.
             |
             | Between selecting due rows and taking this lock, another tick —
             | or the contact themselves — may have moved the chain on. The
             | deadline is re-read under the lock and a run that is no longer
             | overdue is left alone.
             */
            if ($onlyIfDue) {
                $deadline = $locked->next_escalation_at;

                if ($deadline === null || $deadline->isFuture()) {
                    return null;
                }
            }

            if ($closeCurrentWith !== null && $locked->current_step > 0) {
                CheckInEscalationStep::where('check_in_escalation_id', $locked->id)
                    ->where('position', $locked->current_step)
                    ->awaiting()
                    ->update([
                        'status' => $closeCurrentWith,
                        'responded_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $next = $locked->current_step + 1;

            if ($next > $locked->total_steps) {
                $locked->forceFill([
                    'status' => CheckInEscalation::STATUS_EXHAUSTED,
                    'completed_at' => now(),
                    'next_escalation_at' => null,
                ])->save();

                return null;
            }

            $step = CheckInEscalationStep::where('check_in_escalation_id', $locked->id)
                ->where('position', $next)
                ->first();

            if ($step === null) {
                /*
                 | A gap in the steps, which should be impossible — they are
                 | written in one transaction with a unique index on position.
                 | If it ever happens, ending the run is far better than
                 | leaving a chain pending forever with a deadline nobody can
                 | satisfy.
                 */
                $locked->forceFill([
                    'status' => CheckInEscalation::STATUS_EXHAUSTED,
                    'completed_at' => now(),
                    'next_escalation_at' => null,
                ])->save();

                return null;
            }

            $step->forceFill([
                'status' => CheckInEscalationStep::STATUS_NOTIFIED,
                'notified_at' => now(),
            ])->save();

            $locked->forceFill([
                'current_step' => $next,
                'next_escalation_at' => now()->addMinutes($locked->step_timeout_minutes),
            ])->save();

            return $step;
        });

        $fresh = $escalation->fresh(['user', 'steps.contact', 'acknowledgedBy']);

        if ($fresh === null) {
            return;
        }

        if ($notify !== null) {
            $this->tellContact($fresh, $notify);

            return;
        }

        // Ran out of people. Say so, once, to the person who checked in.
        if ($fresh->status === CheckInEscalation::STATUS_EXHAUSTED) {
            $this->tellOwnerNobodyAnswered($fresh);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Telling people
    |--------------------------------------------------------------------------
    */

    /**
     * The request itself: a feed row that survives a closed app, and a
     * websocket frame for a phone that is open.
     *
     * In that order, and it matters. The row is written first and its id is
     * carried in the frame, so a banner tapped the instant it lands resolves
     * to a notification that already exists. Doing it the other way round
     * gives a banner pointing at nothing for however long the write takes.
     */
    private function tellContact(CheckInEscalation $escalation, CheckInEscalationStep $step): void
    {
        $owner = $escalation->user;
        $contact = $step->contact ?? $step->contact()->first();

        if ($owner === null || $contact === null) {
            return;
        }

        try {
            $notification = $this->notifier->push(
                to: $contact,
                actor: $owner,
                type: UserNotification::CHECK_IN_REQUESTED,
                subject: $step,
                data: [
                    'message' => $this->firstName($owner)
                        .' checked in safe today. Let them know you have seen it.',
                    'escalation_id' => $escalation->uuid,
                    'position' => $step->position,
                    'total' => $escalation->total_steps,
                ],
            );

            if ($notification !== null) {
                $step->forceFill(['user_notification_id' => $notification->id])->save();
            }
        } catch (\Throwable $e) {
            Log::error('check-in request notification failed', [
                'step' => $step->uuid,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            CheckInRequested::dispatch($escalation, $step->fresh(['contact']), $owner, $contact);
        } catch (\Throwable $e) {
            Log::error('check-in request broadcast failed', [
                'step' => $step->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Somebody confirmed. Tell the person who checked in. */
    private function tellOwner(User $owner, User $contact, CheckInEscalation $escalation): void
    {
        try {
            $this->notifier->push(
                to: $owner,
                actor: $contact,
                type: UserNotification::CHECK_IN_ACKNOWLEDGED,
                subject: $escalation,
                data: [
                    'message' => $this->firstName($contact)
                        .' knows you are safe today.',
                    'escalation_id' => $escalation->uuid,
                ],
            );
        } catch (\Throwable $e) {
            Log::error('check-in acknowledgement notification failed', [
                'escalation' => $escalation->uuid,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            CheckInAcknowledged::dispatch($escalation, $owner, $contact);
        } catch (\Throwable $e) {
            Log::error('check-in acknowledgement broadcast failed', [
                'escalation' => $escalation->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Nobody answered.
     *
     * Worth saying plainly rather than letting the chain fade out. The person
     * checked in *so that* somebody would know; a silent chain that reached
     * the end has failed at the only thing it was for, and they should be able
     * to see that and call someone.
     */
    private function tellOwnerNobodyAnswered(CheckInEscalation $escalation): void
    {
        $owner = $escalation->user;

        if ($owner === null) {
            return;
        }

        try {
            $this->notifier->push(
                to: $owner,
                // No actor: nobody did this, which is the point.
                actor: null,
                type: UserNotification::CHECK_IN_UNANSWERED,
                subject: $escalation,
                data: [
                    'message' => 'Nobody confirmed your check-in today.',
                    'escalation_id' => $escalation->uuid,
                ],
            );
        } catch (\Throwable $e) {
            Log::error('check-in exhausted notification failed', [
                'escalation' => $escalation->uuid,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            CheckInAcknowledged::dispatch($escalation, $owner, null);
        } catch (\Throwable $e) {
            Log::error('check-in exhausted broadcast failed', [
                'escalation' => $escalation->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark the feed row read once its question has been answered.
     *
     * The row stays — "Faisal checked in and you confirmed it" is true history.
     * Only the unread badge clears, which is the same thing accepting a follow
     * request does.
     */
    private function closeNotification(CheckInEscalationStep $step): void
    {
        if ($step->user_notification_id === null) {
            return;
        }

        try {
            UserNotification::whereKey($step->user_notification_id)
                ->unread()
                ->update(['read_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('check-in notification close failed', [
                'step' => $step->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    */

    /**
     * The chain, as the circular view draws it.
     *
     * The server owns the words and the states; the client owns the geometry.
     * That split is deliberate — where the progress bar reaches is a fact
     * about avatar positions on a particular screen width, which this end of
     * the wire knows nothing about, while what "Kulsoom knows you are safe"
     * says must be identical in the app and the dashboard.
     *
     * @return array<string, mixed>|null
     */
    public function present(?CheckInEscalation $escalation): ?array
    {
        if ($escalation === null) {
            return null;
        }

        $steps = $escalation->relationLoaded('steps')
            ? $escalation->steps
            : $escalation->steps()->with('contact')->get();

        $acknowledger = $escalation->acknowledged_by_id === null
            ? null
            : ($escalation->acknowledgedBy ?? $escalation->acknowledgedBy()->first());

        return [
            'id' => $escalation->uuid,
            'status' => $escalation->status,
            'current_step' => $escalation->current_step,
            'total_steps' => $escalation->total_steps,
            'timeout_minutes' => $escalation->step_timeout_minutes,

            'tone' => match ($escalation->status) {
                CheckInEscalation::STATUS_ACKNOWLEDGED => 'positive',
                CheckInEscalation::STATUS_EXHAUSTED => 'caution',
                CheckInEscalation::STATUS_CANCELLED => 'muted',
                default => 'pending',
            },

            'headline' => $this->headline($escalation, $acknowledger),
            'detail' => $this->detail($escalation, $steps),

            'acknowledged_by' => $acknowledger === null
                ? null
                : $this->person($acknowledger),
            'acknowledged_at' => $escalation->acknowledged_at?->toIso8601String(),

            'started_at' => $escalation->started_at?->toIso8601String(),
            'completed_at' => $escalation->completed_at?->toIso8601String(),

            /*
             | When the request moves on, so the client can run its own
             | countdown. Sent as an instant rather than a remaining-seconds
             | number, which would be wrong the moment after it was sent.
             */
            'next_escalation_at' => $escalation->next_escalation_at?->toIso8601String(),

            'steps' => $steps
                ->map(fn (CheckInEscalationStep $step) => [
                    'id' => $step->uuid,
                    'position' => $step->position,
                    'status' => $step->status,
                    'notified_at' => $step->notified_at?->toIso8601String(),
                    'responded_at' => $step->responded_at?->toIso8601String(),
                    'person' => $step->contact === null
                        ? null
                        : $this->person($step->contact),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * One incoming request, as the recipient's banner draws it.
     *
     * @return array<string, mixed>
     */
    public function request(CheckInEscalationStep $step): array
    {
        $escalation = $step->escalation;
        $owner = $escalation?->user;

        return [
            'id' => $step->uuid,
            'escalation_id' => $escalation?->uuid,
            'position' => $step->position,
            'total' => $escalation?->total_steps ?? 0,
            'notified_at' => $step->notified_at?->toIso8601String(),
            'expires_at' => $escalation?->next_escalation_at?->toIso8601String(),
            'person' => $owner === null ? null : $this->person($owner),
            'message' => $owner === null
                ? 'Someone checked in safe today.'
                : $this->firstName($owner)
                    .' checked in safe today. Let them know you have seen it.',
        ];
    }

    private function headline(CheckInEscalation $escalation, ?User $acknowledger): string
    {
        return match ($escalation->status) {
            CheckInEscalation::STATUS_ACKNOWLEDGED => $acknowledger === null
                ? 'Confirmed'
                : $this->firstName($acknowledger).' knows you are safe',
            CheckInEscalation::STATUS_EXHAUSTED => 'Nobody confirmed',
            CheckInEscalation::STATUS_CANCELLED => 'Not sent',
            default => 'Letting your family know',
        };
    }

    /**
     * @param  Collection<int, CheckInEscalationStep>  $steps
     */
    private function detail(CheckInEscalation $escalation, Collection $steps): string
    {
        if ($escalation->status === CheckInEscalation::STATUS_ACKNOWLEDGED) {
            $passed = $steps
                ->filter(fn (CheckInEscalationStep $s) => in_array(
                    $s->status,
                    [CheckInEscalationStep::STATUS_REJECTED, CheckInEscalationStep::STATUS_EXPIRED],
                    true,
                ))
                ->count();

            return $passed === 0
                ? 'Confirmed by the first person you chose.'
                : 'Confirmed after '.$passed.' '.($passed === 1 ? 'person' : 'people').' passed.';
        }

        if ($escalation->status === CheckInEscalation::STATUS_EXHAUSTED) {
            return 'Everyone on your list was asked and nobody answered.';
        }

        if ($escalation->status === CheckInEscalation::STATUS_CANCELLED) {
            return 'This chain was called off.';
        }

        $current = $steps->firstWhere('position', $escalation->current_step);
        $name = $current?->contact === null ? null : $this->firstName($current->contact);

        if ($name === null) {
            return 'Reaching out to your family.';
        }

        return 'Waiting for '.$name.'. Moves on in '
            .$escalation->step_timeout_minutes.' minutes if there is no answer.';
    }

    /**
     * What to say to somebody who answered a step that had already closed.
     */
    private function settledMessage(CheckInEscalation $escalation, CheckInEscalationStep $step): string
    {
        if ($step->status === CheckInEscalationStep::STATUS_ACCEPTED) {
            return 'You already confirmed this one.';
        }

        if ($escalation->isAcknowledged()) {
            $who = $escalation->acknowledgedBy ?? $escalation->acknowledgedBy()->first();

            return $who === null
                ? 'Someone else already confirmed this.'
                : $this->firstName($who).' already confirmed this.';
        }

        return match ($step->status) {
            CheckInEscalationStep::STATUS_EXPIRED =>
                'This moved on to the next person while you were away.',
            CheckInEscalationStep::STATUS_SKIPPED =>
                'This was settled before it reached you.',
            default => 'This request has already been answered.',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Accepted family, from both ends of the link.
     *
     * The same rule SOS uses, and for the same reason: a family link is one
     * row meant mutually, so the person who sent the invite and the person who
     * accepted it are equally family.
     *
     * @return Collection<int, User>
     */
    private function familyOf(User $owner): Collection
    {
        return FamilyMember::query()
            ->accepted()
            ->involving($owner->id)
            ->with(['owner', 'member'])
            ->get()
            ->map(fn (FamilyMember $link) => $link->counterpartFor($owner))
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function person(User $user): array
    {
        return [
            'id' => $user->uuid,
            'name' => $user->name,
            'username' => $user->username,
            'avatar_url' => $user->avatar_url,
        ];
    }

    /**
     * First name only, for sentences.
     *
     * "Kulsoom knows you are safe" reads as a family member; "Kulsoom Khan
     * knows you are safe" reads as a system notice about a record.
     */
    private function firstName(User $user): string
    {
        $name = trim((string) $user->name);

        if ($name === '') {
            return 'Someone';
        }

        $parts = preg_split('/\s+/', $name) ?: [$name];

        return $parts[0];
    }
}
