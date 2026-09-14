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

    /**
     * It is your turn to confirm somebody's daily check-in.
     *
     * The only notification in the system with a deadline on it: if it is not
     * answered, the request moves to the next person on the list and this row
     * stops being actionable — resolved, like every other action here, from
     * the step it points at rather than from anything stored on the row.
     */
    public const CHECK_IN_REQUESTED = 'check_in.requested';

    /** Somebody confirmed they know you are safe. */
    public const CHECK_IN_ACKNOWLEDGED = 'check_in.acknowledged';

    /** Your whole list was asked and nobody answered. */
    public const CHECK_IN_UNANSWERED = 'check_in.unanswered';

    /**
     * Somebody put a reminder on your phone.
     *
     * Actionable until answered, and resolved from the reminder's own
     * assignment status rather than stored here — the same rule as every
     * other request in this feed.
     */
    public const REMINDER_ASSIGNED = 'reminder.assigned';

    public const REMINDER_ACCEPTED = 'reminder.accepted';
    public const REMINDER_DECLINED = 'reminder.declined';

    /**
     * They did the thing you reminded them to do.
     *
     * The payoff for assigning a reminder at all: "Dad took his tablet" is
     * the whole reason a daughter sets one on her father's phone.
     */
    public const REMINDER_DONE = 'reminder.done';

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
