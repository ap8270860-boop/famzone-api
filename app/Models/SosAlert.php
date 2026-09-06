<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One alarm.
 *
 * @property-read User $user
 * @property-read LocationShare|null $locationShare
 */
#[Fillable([
    'category', 'status', 'latitude', 'longitude', 'accuracy',
    'address', 'battery_level', 'note', 'started_at', 'ended_at',
])]
class SosAlert extends Model
{
    /** Still running. Exactly one of these per person at a time. */
    public const STATUS_ACTIVE = 'active';

    /** Ended by the person, having actually needed it. */
    public const STATUS_RESOLVED = 'resolved';

    /** Ended by the person before it was needed. */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Pressed by accident.
     *
     * Kept distinct from cancelled on purpose. Both close the alert, but they
     * mean different things to whoever reads the history later — and a person
     * who can say "that was my pocket" without it looking like a real
     * emergency they backed out of is a person who will keep the button
     * enabled.
     */
    public const STATUS_FALSE_ALARM = 'false_alarm';

    public const CLOSING_STATUSES = [
        self::STATUS_RESOLVED,
        self::STATUS_CANCELLED,
        self::STATUS_FALSE_ALARM,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $alert) {
            $alert->uuid ??= (string) Str::uuid7();
            $alert->started_at ??= now();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy' => 'integer',
            'battery_level' => 'integer',
            'notified_count' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, SosAlert>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<LocationShare, SosAlert>
     */
    public function locationShare(): BelongsTo
    {
        return $this->belongsTo(LocationShare::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * @param  Builder<SosAlert>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * @param  Builder<SosAlert>  $query
     */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('started_at');
    }
}
