<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A run of the check-in notification chain.
 *
 * @property-read User $user
 * @property-read User|null $acknowledgedBy
 * @property-read SafetyCheckIn $checkIn
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CheckInEscalationStep> $steps
 */
#[Fillable([
    'status', 'current_step', 'total_steps', 'step_timeout_minutes',
    'acknowledged_at', 'started_at', 'completed_at', 'next_escalation_at',
])]
class CheckInEscalation extends Model
{
    /** Somebody still has it, or is about to. */
    public const STATUS_PENDING = 'pending';

    /** Somebody said they know. The chain stops here. */
    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    /** Everybody in the list has been asked and nobody confirmed. */
    public const STATUS_EXHAUSTED = 'exhausted';

    /** Called off — the owner removed the check-in, or their account went. */
    public const STATUS_CANCELLED = 'cancelled';

    /** The default wait before the request moves on. */
    public const DEFAULT_TIMEOUT_MINUTES = 30;

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
            'current_step' => 'integer',
            'total_steps' => 'integer',
            'step_timeout_minutes' => 'integer',
            'acknowledged_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'next_escalation_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, CheckInEscalation>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, CheckInEscalation>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_id');
    }

    /**
     * @return BelongsTo<SafetyCheckIn, CheckInEscalation>
     */
    public function checkIn(): BelongsTo
    {
        return $this->belongsTo(SafetyCheckIn::class, 'safety_check_in_id');
    }

    /**
     * @return HasMany<CheckInEscalationStep, CheckInEscalation>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(CheckInEscalationStep::class)->orderBy('position');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isAcknowledged(): bool
    {
        return $this->status === self::STATUS_ACKNOWLEDGED;
    }

    /** Whether the run has stopped, however it stopped. */
    public function isSettled(): bool
    {
        return $this->status !== self::STATUS_PENDING;
    }

    /**
     * Still waiting, and the wait has run out.
     *
     * @param  Builder<CheckInEscalation>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING)
            ->whereNotNull('next_escalation_at')
            ->where('next_escalation_at', '<=', now());
    }

    /**
     * @param  Builder<CheckInEscalation>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING);
    }
}
