<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's bookmark on one message.
 *
 * Private by construction: nothing about a star is ever broadcast, and the
 * other person in the thread has no way to learn that you kept something.
 *
 * @property-read Message $message
 * @property-read User $user
 */
class MessageStar extends Model
{
    /** Only ever written once, so there is nothing for updated_at to record. */
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Message, MessageStar>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<User, MessageStar>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
