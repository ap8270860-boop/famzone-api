<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A cached answer from Google Places.
 */
#[Fillable(['cache_key', 'payload', 'expires_at'])]
class PlaceLookup extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * A live entry, or null.
     *
     * Expiry is evaluated in the query rather than after loading, so a stale
     * row is never read into memory and then discarded — and so the index on
     * expires_at is actually used.
     *
     * @return array<string, mixed>|null
     */
    public static function fresh_(string $key): ?array
    {
        $row = self::query()
            ->where('cache_key', $key)
            ->where('expires_at', '>', now())
            ->first();

        return $row?->payload;
    }

    /**
     * Write, or overwrite an expired one.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function put(string $key, array $payload, int $days): void
    {
        self::updateOrCreate(
            ['cache_key' => $key],
            ['payload' => $payload, 'expires_at' => now()->addDays($days)],
        );
    }

    /**
     * @param  Builder<PlaceLookup>  $query
     */
    public function scopeExpired(Builder $query): void
    {
        $query->where('expires_at', '<=', now());
    }
}
