<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One moment a watermark moved.
 *
 * Written only when a watermark actually advances, so a thread with sixty
 * unread messages produces one row rather than sixty. See the migration for
 * why this exists at all rather than a timestamp column on the participant.
 *
 * @property-read Conversation $conversation
 * @property-read User $user
 */
class ReceiptMark extends Model
{
    /** Marks are never updated, only written. */
    public $timestamps = false;

    public const KIND_DELIVERED = 'delivered';
    public const KIND_READ = 'read';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'marked_at' => 'datetime',
        ];
    }

    /**
     * When this person's [kind] watermark first reached [seq].
     *
     * The first mark at or past the message, not the last one before it: the
     * watermark that covered this message is the one that read it.
     */
    public static function whenReached(
        int $conversationId,
        int $userId,
        string $kind,
        int $seq,
    ): ?\Illuminate\Support\Carbon {
        return self::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->where('kind', $kind)
            ->where('seq', '>=', $seq)
            ->orderBy('seq')
            ->value('marked_at');
    }

    /**
     * @return BelongsTo<Conversation, ReceiptMark>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, ReceiptMark>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
