<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One entry in a user's notification feed.
 *
 * @property-read User $actor
 */
#[Fillable(['type', 'subject_type', 'subject_id', 'data', 'read_at'])]
class UserNotification extends Model
{
    public const FOLLOW_REQUESTED = 'follow.requested';
    public const FOLLOW_ACCEPTED = 'follow.accepted';

    /** A public account was followed — nothing to approve. */
    public const FOLLOW_STARTED = 'follow.started';
    public const FAMILY_INVITED = 'family.invited';
    public const FAMILY_ACCEPTED = 'family.accepted';

    protected static function booted(): void
    {
        static::creating(function (self $notification) {
            $notification->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, UserNotification>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    /**
     * @param  Builder<UserNotification>  $query
     */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    /**
     * @param  Builder<UserNotification>  $query
     */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @param  Builder<UserNotification>  $query
     */
    public function scopeForSubject(Builder $query, string $type, int $id): void
    {
        $query->where('subject_type', $type)->where('subject_id', $id);
    }
}
