<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reading from one phone.
 *
 * Immutable, like a check-in: a measurement is never corrected, only
 * superseded by the next one.
 */
#[Fillable([
    'latitude', 'longitude', 'accuracy', 'speed', 'heading',
    'battery_level', 'moving', 'recorded_at',
])]
class LocationPing extends Model
{
    /**
     * There is no updated_at column, and Eloquent will happily try to write
     * one on save if it is not told.
     */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy' => 'integer',
            'speed' => 'float',
            'heading' => 'float',
            'battery_level' => 'integer',
            'moving' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, LocationPing>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<LocationPing>  $query
     */
    public function scopeSince(Builder $query, \DateTimeInterface $moment): void
    {
        $query->where('recorded_at', '>=', $moment);
    }
}
