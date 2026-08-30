<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's emoji on one message.
 *
 * @property-read Message $message
 * @property-read User $user
 */
#[Fillable(['emoji'])]
class MessageReaction extends Model
{
    /**
     * @return BelongsTo<Message, MessageReaction>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<User, MessageReaction>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
