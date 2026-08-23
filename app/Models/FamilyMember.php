<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A family link between two users.
 *
 * Stored one-directional (owner invited member) but meant mutually: once
 * accepted, each shows up in the other's family list. One row rather than two
 * means an accept cannot half-apply and leave a one-sided family.
 *
 * @property-read User $owner
 * @property-read User $member
 */
#[Fillable(['status', 'relation', 'reverse_relation', 'invited_at', 'responded_at'])]
class FamilyMember extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_REMOVED = 'removed';

    /** Suggestions for the picker. The column is free text — see the migration. */
    public const RELATIONS = [
        'mother', 'father', 'sister', 'brother', 'son', 'daughter',
        'spouse', 'partner', 'grandparent', 'grandchild', 'friend', 'other',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $member) {
            $member->uuid ??= (string) Str::uuid7();
            $member->invited_at ??= now();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, FamilyMember>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, FamilyMember>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** The other party, seen from one user's side. */
    public function counterpartFor(User $user): User
    {
        return $this->owner_id === $user->id ? $this->member : $this->owner;
    }

    /** How $user labels the other person. */
    public function relationFor(User $user): ?string
    {
        return $this->owner_id === $user->id ? $this->relation : $this->reverse_relation;
    }

    /**
     * @param  Builder<FamilyMember>  $query
     */
    public function scopeAccepted(Builder $query): void
    {
        $query->where('status', self::STATUS_ACCEPTED);
    }

    /**
     * Every family row touching this user, from either end.
     *
     * @param  Builder<FamilyMember>  $query
     */
    public function scopeInvolving(Builder $query, int $userId): void
    {
        $query->where(function (Builder $q) use ($userId) {
            $q->where('owner_id', $userId)->orWhere('member_id', $userId);
        });
    }
}
