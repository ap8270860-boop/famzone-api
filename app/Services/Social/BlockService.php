<?php

namespace App\Services\Social;

use App\Models\Block;
use App\Models\FamilyMember;
use App\Models\Follow;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Blocking, and everything it has to tear down.
 *
 * The thing that makes blocking hard is not the row — it is that a block has
 * to undo a relationship that already exists, in both directions, without
 * telling the other person it happened. Half a block is worse than none: an
 * old follow edge left behind means the person you blocked still appears in
 * your followers list and still counts toward your numbers.
 *
 * So a block is a transaction over four things: follows both ways, family
 * membership either way, the notifications each has about the other, and only
 * then the block row itself.
 */
class BlockService
{
    /**
     * Block somebody.
     *
     * Idempotent — blocking twice is the same block, and returns quietly
     * rather than erroring, because a double tap is not a mistake worth
     * reporting.
     */
    public function block(User $actor, User $target, ?string $reason = null): Block
    {
        if ($actor->id === $target->id) {
            throw ValidationException::withMessages([
                'block' => ['You cannot block yourself.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $target, $reason): Block {
            $existing = Block::where('blocker_id', $actor->id)
                ->where('blocked_id', $target->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $this->severEverything($actor, $target);

            $block = new Block();
            $block->blocker_id = $actor->id;
            $block->blocked_id = $target->id;
            $block->reason = $reason;
            $block->blocked_at = now();
            $block->save();

            return $block;
        });
    }

    /**
     * Lift a block.
     *
     * Deliberately does NOT restore anything it tore down. The follows are
     * gone, the family link is gone, and getting them back means asking again.
     * Silently reinstating a follow somebody had blocked their way out of
     * would be a nasty surprise for both parties.
     */
    public function unblock(User $actor, User $target): void
    {
        Block::where('blocker_id', $actor->id)
            ->where('blocked_id', $target->id)
            ->delete();
    }

    /**
     * Remove every connection between two people.
     *
     * Runs inside the block transaction, so a failure anywhere leaves the pair
     * exactly as they were rather than half-separated.
     */
    private function severEverything(User $actor, User $target): void
    {
        // Follows, both directions. Deleted rather than marked declined —
        // a block is not a decision about a request, it is the end of the
        // relationship.
        Follow::query()
            ->where(function (Builder $q) use ($actor, $target) {
                $q->where(fn (Builder $i) => $i->where('follower_id', $actor->id)->where('followee_id', $target->id))
                    ->orWhere(fn (Builder $i) => $i->where('follower_id', $target->id)->where('followee_id', $actor->id));
            })
            ->delete();

        // Family membership, whichever of them owns it. Marked removed rather
        // than deleted, because the history of who was once in a circle is
        // worth keeping — see the family_members migration.
        FamilyMember::query()
            ->where(function (Builder $q) use ($actor, $target) {
                $q->where(fn (Builder $i) => $i->where('owner_id', $actor->id)->where('member_id', $target->id))
                    ->orWhere(fn (Builder $i) => $i->where('owner_id', $target->id)->where('member_id', $actor->id));
            })
            ->whereIn('status', [FamilyMember::STATUS_PENDING, FamilyMember::STATUS_ACCEPTED])
            ->update([
                'status' => FamilyMember::STATUS_REMOVED,
                'responded_at' => now(),
                'updated_at' => now(),
            ]);

        // Notifications each holds about the other, in both directions.
        //
        // Both matter. Leaving the blocked person a live "wants to follow you"
        // card would let them accept a request from somebody who has since
        // blocked them — and that acceptance would then be refused, which
        // tells them exactly what happened.
        UserNotification::query()
            ->where(function (Builder $q) use ($actor, $target) {
                $q->where(fn (Builder $i) => $i->where('user_id', $actor->id)->where('actor_id', $target->id))
                    ->orWhere(fn (Builder $i) => $i->where('user_id', $target->id)->where('actor_id', $actor->id));
            })
            ->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    */

    /** Whether a wall exists between two users, in either direction. */
    public function wallExists(int $a, int $b): bool
    {
        return Block::between($a, $b)->exists();
    }

    /** Whether $actor is the one who put it up. */
    public function hasBlocked(int $actorId, int $targetId): bool
    {
        return Block::where('blocker_id', $actorId)
            ->where('blocked_id', $targetId)
            ->exists();
    }

    /**
     * The accounts this user has blocked, newest first.
     *
     * Reachable from settings, which is the only way back: a blocked account
     * is hidden from search, so without this list a block would be permanent
     * by accident.
     *
     * @return array<string, mixed>
     */
    public function blockedList(User $user, int $limit = 100): array
    {
        $blocks = Block::where('blocker_id', $user->id)
            ->with('blocked')
            ->orderByDesc('blocked_at')
            ->limit($limit)
            ->get();

        return [
            'total' => $blocks->count(),
            'blocked' => $blocks->map(function (Block $block) {
                $person = $block->blocked;

                return [
                    'id' => $person?->uuid,
                    'name' => $person?->name,
                    'username' => $person?->username,
                    // The real avatar: you know who you blocked, and showing a
                    // decoy here would make the list useless.
                    'avatar_url' => $person?->avatar_url,
                    'blocked_at' => $block->blocked_at?->toIso8601String(),

                    // Enough for the row to render an Unblock button without
                    // a relationship lookup — everything else is severed by
                    // definition.
                    'relationship' => [
                        'is_self' => false,
                        'blocked_by_me' => true,
                        'following' => 'none',
                        'followed_by' => 'none',
                        'family' => 'none',
                        'can_follow' => false,
                    ],
                ];
            })->filter(fn (array $row) => $row['id'] !== null)->values()->all(),
        ];
    }
}
