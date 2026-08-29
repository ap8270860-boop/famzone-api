<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A file, image or voice note attached to a message.
 *
 * Nothing writes to this yet — media is the last phase. The model exists now
 * so the relationship, the casts and the signed-URL accessor are settled
 * alongside the rest of the schema rather than bolted on later.
 *
 * @property-read Message $message
 */
#[Fillable([
    'disk', 'path', 'mime', 'original_name', 'size_bytes',
    'width', 'height', 'duration_ms', 'waveform',
])]
class MessageAttachment extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $attachment) {
            $attachment->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
            'waveform' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Message, MessageAttachment>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
