<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * What happened on one occurrence of one reminder.
 *
 * Written only when an occurrence settles — see the migration for why the
 * future is computed and the past is stored.
 *
 * @property-read Reminder $reminder
 */
/*
 | Both foreign keys are fillable, deliberately.
 |
 | Every row in this table is written by ReminderService — through
 | updateOrCreate when somebody answers an occurrence, and through create when
 | the nightly close-out writes off a day that went unanswered. Both build
 | their arrays from $reminder->id and $user->id, which are resolved from a
 | route binding and the authenticated user long before they reach here. No
 | request array is ever handed to this model.
 |
 | So leaving them out guarded nothing. It only meant Eloquent quietly dropped
 | them on the way through fill() and handed MySQL an insert with no
 | reminder_id — which is error 1364, and which does not appear until the first
 | time somebody actually answers a reminder.
 */
#[Fillable([
    'reminder_id', 'user_id',
    'due_on', 'due_at', 'status', 'completed_at', 'snoozed_until',
])]
class ReminderOccurrence extends Model
{
    public const STATUS_DONE = 'done';
    public const STATUS_MISSED = 'missed';
    public const STATUS_SNOOZED = 'snoozed';

    /** Deliberately not done — "not today", said on purpose. */
    public const STATUS_SKIPPED = 'skipped';

    /**
     * What counts towards the score's denominator.
     *
     * Skipped does not. Somebody who consciously decides not to go to the gym
     * on a rest day has not failed at anything, and counting it against them
     * teaches them to ignore the reminder rather than to answer it honestly.
     *
     * @var list<string>
     */
    public const COUNTED_STATUSES = [self::STATUS_DONE, self::STATUS_MISSED];

    /** Still waiting on a second answer, so not settled. */
    public const OPEN_STATUSES = [self::STATUS_SNOOZED];

    protected static function booted(): void
    {
        static::creating(function (self $row) {
            $row->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'snoozed_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Reminder, ReminderOccurrence>
     */
    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    /**
     * @param  Builder<ReminderOccurrence>  $query
     */
    public function scopeForDay(Builder $query, int $userId, string $date): void
    {
        $query->where('user_id', $userId)->where('due_on', $date);
    }

    /**
     * @param  Builder<ReminderOccurrence>  $query
     */
    public function scopeBetween(Builder $query, int $userId, string $from, string $to): void
    {
        $query->where('user_id', $userId)->whereBetween('due_on', [$from, $to]);
    }
}
