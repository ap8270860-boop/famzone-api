<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's membership of one thread.
 *
 * @property-read Conversation $conversation
 * @property-read User $user
 */
#[Fillable(['state', 'muted_until'])]
class ConversationParticipant extends Model
{
    public const STATE_PENDING = 'pending';
    public const STATE_ACCEPTED = 'accepted';
    public const STATE_ARCHIVED = 'archived';

    protected static function booted(): void
    {
        static::creating(function (self $participant) {
            $participant->joined_at ??= now();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_read_seq' => 'integer',
            'last_delivered_seq' => 'integer',
            'unread_count' => 'integer',
            'marked_unread' => 'boolean',
            'muted_until' => 'datetime',
            'pinned_at' => 'datetime',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, ConversationParticipant>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, ConversationParticipant>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    public function hasLeft(): bool
    {
        return $this->left_at !== null;
    }

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    /**
     * @param  Builder<ConversationParticipant>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('left_at');
    }
}
