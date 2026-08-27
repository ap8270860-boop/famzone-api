<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One person blocking another.
 *
 * @property-read User $blocker
 * @property-read User $blocked
 */
#[Fillable(['reason', 'blocked_at'])]
class Block extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $block) {
            $block->uuid ??= (string) Str::uuid7();
            $block->blocked_at ??= now();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['blocked_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, Block>
     */
    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocker_id');
    }

    /**
     * @return BelongsTo<User, Block>
     */
    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_id');
    }

    /**
     * A block in either direction between two users.
     *
     * Most checks care only that a wall exists, not who built it — following,
     * searching and profile reads are all refused either way round.
     *
     * @param  Builder<Block>  $query
     */
    public function scopeBetween(Builder $query, int $a, int $b): void
    {
        $query->where(function (Builder $q) use ($a, $b) {
            $q->where(fn (Builder $i) => $i->where('blocker_id', $a)->where('blocked_id', $b))
                ->orWhere(fn (Builder $i) => $i->where('blocker_id', $b)->where('blocked_id', $a));
        });
    }

    /**
     * Every user id $userId cannot interact with, in either direction.
     *
     * Returned as a subquery rather than a list of ids so callers can compose
     * it into a whereNotIn without a round trip. Search runs this on every
     * keystroke; loading a block list into PHP first would not scale.
     *
     * @return Builder<Block>
     */
    public static function wallIds(int $userId): Builder
    {
        return static::query()
            ->selectRaw('CASE WHEN blocker_id = ? THEN blocked_id ELSE blocker_id END', [$userId])
            ->where(function (Builder $q) use ($userId) {
                $q->where('blocker_id', $userId)->orWhere('blocked_id', $userId);
            });
    }
}
