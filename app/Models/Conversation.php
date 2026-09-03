<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A thread between people.
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ConversationParticipant> $participants
 * @property-read ?Message $lastMessage
 */
#[Fillable(['type', 'pair_key', 'title'])]
class Conversation extends Model
{
    public const TYPE_DIRECT = 'direct';
    public const TYPE_GROUP = 'group';

    protected static function booted(): void
    {
        static::creating(function (self $conversation) {
            $conversation->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seq' => 'integer',
            'last_message_at' => 'datetime',
            'pinned_at' => 'datetime',
        ];
    }

    /**
     * The deterministic key for a one-to-one thread.
     *
     * Sorted before hashing so both people compute the same value regardless
     * of who opened the thread. Hashed rather than stored as "3:41" so the
     * column reveals nothing about internal ids even if the table is ever
     * dumped or replicated somewhere less trusted.
     */
    public static function pairKey(int $a, int $b): string
    {
        $pair = [$a, $b];
        sort($pair);

        return hash('sha256', $pair[0].':'.$pair[1]);
    }

    /**
     * @return HasMany<ConversationParticipant>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /**
     * @return HasMany<Message>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * The message pinned in this thread, shared by everyone in it.
     *
     * withTrashed so a pinned message that is later deleted shows as a
     * tombstone rather than the banner silently emptying — the service
     * clears the pin deliberately instead.
     *
     * @return BelongsTo<Message, Conversation>
     */
    public function pinnedMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'pinned_message_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Message, Conversation>
     */
    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    public function isDirect(): bool
    {
        return $this->type === self::TYPE_DIRECT;
    }

    public function isGroup(): bool
    {
        return $this->type === self::TYPE_GROUP;
    }

    /**
     * Threads a person is still in.
     *
     * left_at rather than deleting the participant row: someone who declines
     * a request and is messaged again should land back in the same thread,
     * not a second one with half the history missing.
     *
     * @param  Builder<Conversation>  $query
     */
    public function scopeForUser(Builder $query, int $userId): void
    {
        $query->whereHas(
            'participants',
            fn (Builder $q) => $q->where('user_id', $userId)->whereNull('left_at'),
        );
    }
}
