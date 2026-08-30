<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One message.
 *
 * @property-read Conversation $conversation
 * @property-read User $sender
 */
#[Fillable(['type', 'body', 'client_uuid'])]
class Message extends Model
{
    use SoftDeletes;

    public const TYPE_TEXT = 'text';
    public const TYPE_IMAGE = 'image';
    public const TYPE_FILE = 'file';
    public const TYPE_AUDIO = 'audio';

    /** Written by the server, never by a person. Joins, leaves, and so on. */
    public const TYPE_SYSTEM = 'system';

    /**
     * Long enough that nobody hits it writing a real message, short enough
     * that a single row cannot be used to push megabytes through the socket.
     */
    public const BODY_MAX = 4000;

    /** Page size for scrollback. */
    public const PER_PAGE = 40;

    protected static function booted(): void
    {
        static::creating(function (self $message) {
            $message->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'forwarded' => 'boolean',
            'edited_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, Message>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, Message>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * @return HasMany<MessageAttachment>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    /**
     * The message this one is answering, if any.
     *
     * withTrashed on purpose: a reply must outlive what it replied to. The
     * quote becomes a tombstone rather than vanishing, because a reply with
     * nothing above it reads as a non-sequitur.
     *
     * @return BelongsTo<Message, Message>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id')->withTrashed();
    }

    /**
     * @return HasMany<MessageReaction>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<MessageAttachment>
     */
    public function attachment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(MessageAttachment::class);
    }

    /** Whether this message is carrying a file rather than text. */
    public function hasMedia(): bool
    {
        return in_array($this->type, [
            self::TYPE_IMAGE,
            self::TYPE_FILE,
            self::TYPE_AUDIO,
        ], true);
    }

    public function isDeleted(): bool
    {
        return $this->trashed();
    }

    /**
     * @param  Builder<Message>  $query
     */
    public function scopeInOrder(Builder $query): void
    {
        $query->orderBy('seq');
    }
}
