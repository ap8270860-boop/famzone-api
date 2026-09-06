<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One person's permission to be seen, for a while.
 *
 * @property-read User $user
 * @property-read Conversation|null $conversation
 * @property-read Message|null $message
 */
#[Fillable(['audience', 'started_at', 'expires_at', 'ended_at'])]
class LocationShare extends Model
{
    /** Bounded to the people already in one thread, and announced in it. */
    public const AUDIENCE_CONVERSATION = 'conversation';

    /** Accepted family only, no expiry, ended by hand. */
    public const AUDIENCE_FAMILY = 'family';

    /**
     * What the duration picker offers, in minutes.
     *
     * Eight hours is the WhatsApp ceiling and it is the right one: long
     * enough to cover a commute or a night out, short enough that somebody
     * who forgets to stop is not still broadcasting tomorrow.
     */
    public const DURATIONS = [15, 60, 480];

    public const MAX_MINUTES = 480;

    protected static function booted(): void
    {
        static::creating(function (self $share) {
            $share->uuid ??= (string) Str::uuid7();
            $share->started_at ??= now();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, LocationShare>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Conversation, LocationShare>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<Message, LocationShare>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Still running, right now.
     *
     * Expiry is evaluated on read rather than swept by a job. A cron that
     * has not run for four minutes would otherwise mean four minutes of
     * broadcasting after a share ended, and "it stops when it says it stops"
     * is not a promise worth making conditional on a scheduler.
     */
    public function isLive(): bool
    {
        return $this->ended_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Live shares only.
     *
     * @param  Builder<LocationShare>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('ended_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}
