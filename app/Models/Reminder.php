<?php

namespace App\Models;

use App\Support\Reminders\Recurrence;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One reminder.
 *
 * @property-read User $owner     Who set it.
 * @property-read User $assignee  Whose phone rings.
 * @property-read ReminderCategory $category
 * @property-read ReminderTemplate|null $template
 */
#[Fillable([
    'title', 'note', 'icon',
    'repeat_mode', 'time_of_day', 'weekday_mask', 'day_of_month',
    'starts_on', 'ends_on', 'timezone',
    'ringtone', 'vibrate', 'snooze_minutes',
    'status', 'meta',
])]
class Reminder extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ARCHIVED = 'archived';

    /** Nobody was assigned — the creator's own reminder. */
    public const ASSIGNMENT_SELF = 'self';
    public const ASSIGNMENT_PENDING = 'pending';
    public const ASSIGNMENT_ACCEPTED = 'accepted';
    public const ASSIGNMENT_DECLINED = 'declined';

    /**
     * The bundled tones, plus the two that are not files.
     *
     * A fixed list and not a path, because on iOS a notification sound has to
     * be compiled into the app to play from a locked phone — so "any file the
     * user picked" is a promise the platform will not keep. `default` hands
     * the phone's own notification sound back to it; `silent` rings nothing
     * and leans on the vibration instead.
     *
     * @var list<string>
     */
    public const RINGTONES = [
        'default', 'gentle', 'chime', 'marimba', 'sunrise',
        'bell', 'pulse', 'classic', 'silent',
    ];

    /** How many reminders one person may have ringing at once. */
    public const MAX_ACTIVE_PER_USER = 60;

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
            'weekday_mask' => 'integer',
            'day_of_month' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'vibrate' => 'boolean',
            'snooze_minutes' => 'integer',
            'meta' => 'array',
            'assignment_responded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, Reminder>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, Reminder>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<ReminderCategory, Reminder>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ReminderCategory::class, 'reminder_category_id');
    }

    /**
     * @return BelongsTo<ReminderTemplate, Reminder>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ReminderTemplate::class, 'reminder_template_id');
    }

    /**
     * @return HasMany<ReminderOccurrence, Reminder>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(ReminderOccurrence::class);
    }

    public function rule(): Recurrence
    {
        return Recurrence::fromReminder($this);
    }

    /** Somebody else set this for me. */
    public function isAssigned(): bool
    {
        return $this->assignment_status !== self::ASSIGNMENT_SELF
            && $this->user_id !== $this->assignee_id;
    }

    public function isAwaitingAnswer(): bool
    {
        return $this->assignment_status === self::ASSIGNMENT_PENDING;
    }

    /**
     * Whether this should actually be ringing on a phone right now.
     *
     * The one predicate the scheduler cares about. An assigned reminder that
     * has not been accepted must not ring: somebody else put it on your phone
     * and you have not said yes.
     */
    public function shouldRing(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && in_array(
                $this->assignment_status,
                [self::ASSIGNMENT_SELF, self::ASSIGNMENT_ACCEPTED],
                true,
            );
    }

    /**
     * Everything that should ring on one person's phone.
     *
     * @param  Builder<Reminder>  $query
     */
    public function scopeRingingFor(Builder $query, int $userId): void
    {
        $query->where('assignee_id', $userId)
            ->where('status', self::STATUS_ACTIVE)
            ->whereIn('assignment_status', [
                self::ASSIGNMENT_SELF,
                self::ASSIGNMENT_ACCEPTED,
            ]);
    }

    /**
     * Everything a person can see: theirs to do, plus what they set for
     * others.
     *
     * @param  Builder<Reminder>  $query
     */
    public function scopeVisibleTo(Builder $query, int $userId): void
    {
        $query->where(function (Builder $q) use ($userId) {
            $q->where('assignee_id', $userId)->orWhere('user_id', $userId);
        })->where('status', '!=', self::STATUS_ARCHIVED);
    }
}
