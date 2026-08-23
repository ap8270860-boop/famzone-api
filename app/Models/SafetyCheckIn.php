<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A single "I am safe" confirmation.
 *
 * Immutable by intent: a check-in records what somebody said at a moment in
 * time. Correcting one means adding another row, never editing this one.
 */
#[Fillable([
    'check_in_date', 'checked_in_at', 'status', 'source', 'note',
    'latitude', 'longitude', 'location_accuracy', 'battery_level',
    'device_type', 'app_version', 'timezone', 'ip_address',
])]
class SafetyCheckIn extends Model
{
    public const STATUS_SAFE = 'safe';
    public const STATUS_UNSAFE = 'unsafe';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SCHEDULED = 'scheduled';
    public const SOURCE_AUTO = 'auto';
    public const SOURCE_SOS = 'sos';

    protected static function booted(): void
    {
        static::creating(function (self $checkIn) {
            $checkIn->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'checked_in_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'location_accuracy' => 'integer',
            'battery_level' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, SafetyCheckIn>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSafe(): bool
    {
        return $this->status === self::STATUS_SAFE;
    }

    /** Whether any location was captured alongside the check-in. */
    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Most recent first.
     *
     * @param  Builder<SafetyCheckIn>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('check_in_date');
    }

    /**
     * @param  Builder<SafetyCheckIn>  $query
     */
    public function scopeBetween(Builder $query, string $from, string $to): void
    {
        $query->whereBetween('check_in_date', [$from, $to]);
    }
}
