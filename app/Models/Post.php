<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * A photo post.
 *
 * @property-read User $user
 */
#[Fillable(['caption', 'image_path', 'image_width', 'image_height', 'status'])]
class Post extends Model
{
    use SoftDeletes;

    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_REMOVED = 'removed';

    /** Matches what people are used to from other apps. */
    public const CAPTION_MAX = 2200;

    /** How many people one post may tag. */
    public const MAX_TAGS = 20;

    protected static function booted(): void
    {
        static::creating(function (self $post) {
            $post->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'image_width' => 'integer',
            'image_height' => 'integer',
            'likes_count' => 'integer',
            'tags_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, Post>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<User>
     */
    public function likers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'post_likes')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User>
     */
    public function taggedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'post_tags')
            ->withTimestamps();
    }

    /**
     * A signed, expiring link to the image.
     *
     * Same reasoning as avatars: posts live on a private disk that nginx does
     * not serve, because a post visible only to accepted followers must not
     * sit behind a URL anyone can fetch. The signature is the credential, so
     * the link works in a plain <img> tag.
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (blank($this->image_path)) {
                return null;
            }

            $disk = Storage::disk(config('filesystems.default'));

            try {
                if ($disk->providesTemporaryUrls()) {
                    return $disk->temporaryUrl(
                        $this->image_path,
                        now()->addHours(User::MEDIA_LINK_HOURS),
                    );
                }
            } catch (\Throwable) {
                // Fall through to the streaming route.
            }

            return URL::temporarySignedRoute(
                'api.v1.media.post',
                now()->addHours(User::MEDIA_LINK_HOURS),
                ['uuid' => $this->uuid],
            );
        });
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * @param  Builder<Post>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * @param  Builder<Post>  $query
     */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
