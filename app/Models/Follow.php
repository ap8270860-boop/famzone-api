<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One directed follow edge: follower_id wants to follow followee_id.
 *
 * @property-read User $follower
 * @property-read User $followee
 */
#[Fillable(['status', 'requested_at', 'responded_at'])]
class Follow extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';

    protected static function booted(): void
    {
        static::creating(function (self $follow) {
            $follow->uuid ??= (string) Str::uuid7();
            $follow->requested_at ??= now();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, Follow>
     */
    public function follower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follower_id');
    }

    /**
     * @return BelongsTo<User, Follow>
     */
    public function followee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'followee_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    /**
     * @param  Builder<Follow>  $query
     */
    public function scopeAccepted(Builder $query): void
    {
        $query->where('status', self::STATUS_ACCEPTED);
    }

    /**
     * @param  Builder<Follow>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING);
    }

    /**
     * The edge between two users in one direction, if any.
     *
     * @param  Builder<Follow>  $query
     */
    public function scopeBetween(Builder $query, int $followerId, int $followeeId): void
    {
        $query->where('follower_id', $followerId)->where('followee_id', $followeeId);
    }
}
