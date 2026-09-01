<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's copy of a message, removed.
 *
 * Deliberately not a soft delete on the message. A message has two readers,
 * and one of them wanting it gone from their own screen says nothing about
 * the other's copy — that is what delete for everyone is for, and it is a
 * different, louder thing.
 *
 * @property-read Message $message
 * @property-read User $user
 */
class MessageHide extends Model
{
    /** Only ever written once, so there is nothing for updated_at to record. */
    public const UPDATED_AT = null;

    /**
     * The message ids this person has hidden, as a subquery.
     *
     * Returned as a query rather than a loaded set so callers can push it
     * into a `whereNotIn` and let the database do the work. Loading every
     * hidden id into PHP would work today and stop working for whoever uses
     * this app for five years.
     *
     * @return Builder<MessageHide>
     */
    public static function idsFor(int $userId): Builder
    {
        return self::query()->select('message_id')->where('user_id', $userId);
    }

    /**
     * @return BelongsTo<Message, MessageHide>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<User, MessageHide>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
