<?php

namespace App\Services\Posts;

use App\Models\Block;
use App\Models\Follow;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Posts: creating them, deciding who may see them, and likes.
 *
 * Visibility is deliberately the same rule as the profile, resolved here
 * rather than re-derived: a post is only as private as the account it belongs
 * to, and if these two ever disagree the looser one wins by accident. Private
 * account plus not an accepted follower means no posts; blocked in either
 * direction means the account does not exist as far as this service is
 * concerned.
 */
class PostService
{
    public const PER_PAGE = 24;

    /*
    |--------------------------------------------------------------------------
    | Visibility
    |--------------------------------------------------------------------------
    */

    /**
     * Whether $viewer may see $owner's posts at all.
     */
    public function canView(User $viewer, User $owner): bool
    {
        if ($viewer->id === $owner->id) {
            return true;
        }

        if (Block::between($viewer->id, $owner->id)->exists()) {
            return false;
        }

        if (! $owner->is_private) {
            return true;
        }

        return Follow::between($viewer->id, $owner->id)->accepted()->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * One user's grid, newest first.
     *
     * @return array<string, mixed>
     */
    public function forUser(User $viewer, User $owner, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        if (! $this->canView($viewer, $owner)) {
            return [
                'can_view' => false,
                'total' => 0, 'page' => 1, 'per_page' => $perPage,
                'has_more' => false, 'posts' => [],
            ];
        }

        $perPage = max(1, min(50, $perPage));

        $query = Post::where('user_id', $owner->id)->published();

        $total = (clone $query)->count();

        $posts = $query->with(['user', 'taggedUsers'])
            ->newestFirst()
            ->forPage($page, $perPage)
            ->get();

        return [
            'can_view' => true,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $page * $perPage < $total,
            'posts' => $this->present($viewer, $posts),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function show(User $viewer, Post $post): ?array
    {
        $post->loadMissing(['user', 'taggedUsers']);

        if (! $this->canView($viewer, $post->user)) {
            return null;
        }

        return $this->present($viewer, new Collection([$post]))[0] ?? null;
    }

    /**
     * Shape posts for the client, resolving "did I like this" for the whole
     * page in one query rather than one per row.
     *
     * @param  Collection<int, Post>  $posts
     * @return list<array<string, mixed>>
     */
    private function present(User $viewer, Collection $posts): array
    {
        if ($posts->isEmpty()) {
            return [];
        }

        $liked = DB::table('post_likes')
            ->where('user_id', $viewer->id)
            ->whereIn('post_id', $posts->pluck('id'))
            ->pluck('post_id')
            ->flip();

        return $posts->map(fn (Post $post) => [
            'id' => $post->uuid,
            'image_url' => $post->image_url,
            'width' => $post->image_width,
            'height' => $post->image_height,
            'caption' => $post->caption,
            'likes_count' => $post->likes_count,
            'liked_by_me' => $liked->has($post->id),
            'created_at' => $post->created_at?->toIso8601String(),
            'is_mine' => $post->user_id === $viewer->id,

            'author' => [
                'id' => $post->user->uuid,
                'name' => $post->user->name,
                'username' => $post->user->username,
                'avatar_url' => $post->user->avatar_url,
            ],

            'tagged' => $post->taggedUsers->map(fn (User $user) => [
                'id' => $user->uuid,
                'name' => $user->name,
                'username' => $user->username,
            ])->values()->all(),
        ])->values()->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Writing
    |--------------------------------------------------------------------------
    */

    /**
     * Publish a post.
     *
     * @param  list<string>  $taggedUuids
     */
    public function create(
        User $author,
        UploadedFile $image,
        ?string $caption,
        array $taggedUuids = [],
    ): Post {
        $tagged = $this->resolveTags($author, $taggedUuids);

        [$width, $height] = $this->dimensions($image);

        $disk = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'));

        $path = "posts/{$author->uuid}/".Str::uuid7().'.'.$image->extension();

        $disk->putFileAs(dirname($path), $image, basename($path), [
            'visibility' => 'private',
        ]);

        try {
            return DB::transaction(function () use ($author, $path, $width, $height, $caption, $tagged): Post {
                $post = new Post();
                $post->user_id = $author->id;
                $post->image_path = $path;
                $post->image_width = $width;
                $post->image_height = $height;
                $post->caption = blank($caption) ? null : trim($caption);
                $post->status = Post::STATUS_PUBLISHED;
                $post->tags_count = $tagged->count();
                $post->save();

                if ($tagged->isNotEmpty()) {
                    $post->taggedUsers()->attach($tagged->pluck('id')->all());
                }

                return $post->load(['user', 'taggedUsers']);
            });
        } catch (\Throwable $e) {
            // The file is already on disk; the row is not. Remove it rather
            // than leaving an orphan nothing will ever reference.
            try {
                $disk->delete($path);
            } catch (\Throwable) {
                // Cleanup is best effort.
            }

            throw $e;
        }
    }

    /**
     * Tagging is limited to people you actually have a relationship with.
     *
     * Without this, a post is a way to attach your name to a stranger's photo
     * — which is how tagging becomes a spam vector on every platform that
     * allows it openly.
     *
     * @param  list<string>  $uuids
     * @return Collection<int, User>
     */
    private function resolveTags(User $author, array $uuids): Collection
    {
        $uuids = array_values(array_unique(array_filter($uuids)));

        if ($uuids === []) {
            return new Collection();
        }

        if (count($uuids) > Post::MAX_TAGS) {
            throw ValidationException::withMessages([
                'tagged' => ['You can tag up to '.Post::MAX_TAGS.' people.'],
            ]);
        }

        $users = User::whereIn('uuid', $uuids)->get();

        if ($users->count() !== count($uuids)) {
            throw ValidationException::withMessages([
                'tagged' => ['One of those accounts no longer exists.'],
            ]);
        }

        // Either direction of an accepted follow counts — somebody who
        // follows you is as taggable as somebody you follow.
        $connected = Follow::query()
            ->accepted()
            ->where(function ($q) use ($author, $users) {
                $ids = $users->pluck('id');

                $q->where(fn ($i) => $i->where('follower_id', $author->id)->whereIn('followee_id', $ids))
                    ->orWhere(fn ($i) => $i->where('followee_id', $author->id)->whereIn('follower_id', $ids));
            })
            ->get()
            ->flatMap(fn (Follow $f) => [$f->follower_id, $f->followee_id])
            ->unique()
            ->flip();

        foreach ($users as $user) {
            if ($user->id === $author->id || ! $connected->has($user->id)) {
                throw ValidationException::withMessages([
                    'tagged' => ['You can only tag people you follow, or who follow you.'],
                ]);
            }
        }

        return $users;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dimensions(UploadedFile $image): array
    {
        $size = @getimagesize($image->getRealPath());

        if ($size === false) {
            throw ValidationException::withMessages([
                'image' => ['That image could not be read.'],
            ]);
        }

        return [(int) $size[0], (int) $size[1]];
    }

    /** Only the author may remove their post. */
    public function delete(User $actor, Post $post): void
    {
        if ($post->user_id !== $actor->id) {
            abort(403, 'That is not your post.');
        }

        DB::transaction(function () use ($post) {
            $post->likers()->detach();
            $post->taggedUsers()->detach();
            $post->delete();
        });

        // The file goes after the row, so a failed delete never leaves a post
        // pointing at bytes that are gone.
        try {
            \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'))
                ->delete($post->image_path);
        } catch (\Throwable) {
            // An orphaned file is a cleanup job, not a failed request.
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Likes
    |--------------------------------------------------------------------------
    */

    /**
     * Like or unlike, and return the post's new state.
     *
     * The unique index is what makes this safe: a double tap racing itself
     * cannot insert twice, and the counter is adjusted only when a row was
     * genuinely created or removed. Recomputing the count from the join table
     * inside the same transaction would also work, but this stays O(1) as a
     * post gets popular.
     *
     * @return array{liked: bool, likes_count: int}
     */
    public function toggleLike(User $actor, Post $post, bool $like): array
    {
        if (! $this->canView($actor, $post->user)) {
            abort(404, 'That post is not available.');
        }

        return DB::transaction(function () use ($actor, $post, $like): array {
            $locked = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();

            if ($like) {
                try {
                    DB::table('post_likes')->insert([
                        'post_id' => $locked->id,
                        'user_id' => $actor->id,
                        'created_at' => now(),
                    ]);

                    $locked->increment('likes_count');
                } catch (QueryException $e) {
                    // Already liked. Not an error — the caller asked for a
                    // state, and the state is already that.
                    if (! str_contains($e->getMessage(), 'Duplicate entry')) {
                        throw $e;
                    }
                }
            } else {
                $removed = DB::table('post_likes')
                    ->where('post_id', $locked->id)
                    ->where('user_id', $actor->id)
                    ->delete();

                if ($removed > 0 && $locked->likes_count > 0) {
                    $locked->decrement('likes_count');
                }
            }

            return [
                'liked' => $like,
                'likes_count' => (int) $locked->fresh()->likes_count,
            ];
        });
    }

    /**
     * Who liked a post.
     *
     * @return array<string, mixed>
     */
    public function likes(User $viewer, Post $post, int $limit = 100): array
    {
        if (! $this->canView($viewer, $post->user)) {
            abort(404, 'That post is not available.');
        }

        $users = $post->likers()
            ->orderByDesc('post_likes.created_at')
            ->limit($limit)
            ->get();

        return [
            'total' => $post->likes_count,
            'likes' => $users->map(fn (User $user) => [
                'id' => $user->uuid,
                'name' => $user->name,
                'username' => $user->username,
                'avatar_url' => $user->avatar_url,
            ])->values()->all(),
        ];
    }
}
