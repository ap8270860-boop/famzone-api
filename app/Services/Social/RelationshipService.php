<?php

namespace App\Services\Social;

use App\Models\Block;
use App\Models\FamilyMember;
use App\Models\Follow;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Following, family membership, and how one user appears to another.
 *
 * The rule that shapes everything here: a relationship has two ends, and both
 * have to be answerable independently. "Do I follow them" and "do they follow
 * me" are separate questions with separate answers, and a screen that conflates
 * them will eventually show somebody a Follow button for a person already
 * following them back. So every relationship read returns both directions
 * explicitly, and every write states which end it is acting on.
 */
class RelationshipService
{
    /** Search needs at least this much to go on. */
    public const MIN_QUERY = 2;

    public const SEARCH_LIMIT = 20;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly BlockService $blocks,
    ) {
    }
    /**
     * Whether a wall stands between the viewer and this person.
     *
     * Cached per request: a single profile read asks this several times over,
     * and it is the same answer every time.
     *
     * @var array<string, bool>
     */
    private array $wallCache = [];

    private function walled(User $viewer, User $target): bool
    {
        $key = $viewer->id.':'.$target->id;

        return $this->wallCache[$key] ??= $this->blocks->wallExists($viewer->id, $target->id);
    }


    /*
    |--------------------------------------------------------------------------
    | Discovery
    |--------------------------------------------------------------------------
    */

    /**
     * Find people by name, username or phone number.
     *
     * Name and username match on a prefix; phone matches only in full.
     *
     * That asymmetry is deliberate. A partial phone match turns this endpoint
     * into a phone-number harvester — type "98765", page through the results,
     * and you have a list of real numbers belonging to real accounts. Requiring
     * the whole number means you can only confirm a number you already have.
     *
     * The prefix rule also happens to be what keeps this fast: MySQL can serve
     * `LIKE 'fai%'` from an index, but `LIKE '%fai%'` forces a full scan.
     *
     * @return array<string, mixed>
     */
    public function search(User $viewer, string $term, int $limit = self::SEARCH_LIMIT): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_QUERY) {
            return ['query' => $term, 'results' => [], 'too_short' => true];
        }

        $digits = preg_replace('/\D/', '', $term);
        $looksLikePhone = $digits !== '' && mb_strlen($digits) >= 6;

        $users = User::query()
            ->where('id', '!=', $viewer->id)
            ->whereNull('deleted_at')
            ->where('status', User::STATUS_ACTIVE)
            // Blocked in either direction: gone from results. A
            // subquery rather than a fetched id list, because this
            // runs on every keystroke.
            ->whereNotIn('id', Block::wallIds($viewer->id))
            ->where(function (Builder $q) use ($term, $digits, $looksLikePhone) {
                $prefix = $this->escapeLike($term).'%';

                $q->where('username', 'like', $prefix)
                    ->orWhere('name', 'like', $prefix)
                    // Also match a prefix of any later word, so "khan" finds
                    // "Faisal Khan" without a leading wildcard.
                    ->orWhere('name', 'like', '% '.$this->escapeLike($term).'%');

                if ($looksLikePhone) {
                    $q->orWhere('phone_number', $digits);
                }
            })
            ->orderByRaw('CASE WHEN username = ? THEN 0 WHEN username LIKE ? THEN 1 ELSE 2 END', [
                $term, $this->escapeLike($term).'%',
            ])
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return [
            'query' => $term,
            'too_short' => false,
            'results' => $this->summaries($viewer, $users),
        ];
    }

    /** Escape the wildcards a user can type, so they are matched literally. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /*
    |--------------------------------------------------------------------------
    | Reading a person
    |--------------------------------------------------------------------------
    */

    /**
     * One user as another user is allowed to see them.
     *
     * @return array<string, mixed>
     */
    public function profile(User $viewer, User $target): array
    {
        // Somebody who blocked you should not be findable, readable
        // or provably present. 404 is what Instagram shows and it is
        // the least disclosing honest answer — anything softer tells
        // the blocked person they were blocked.
        //
        // The blocker still gets the profile, because that is where
        // the Unblock button lives.
        if ($viewer->id !== $target->id
            && $this->blocks->hasBlocked($target->id, $viewer->id)) {
            abort(404, 'That account is not available.');
        }

        $relationship = $this->relationship($viewer, $target);
        $isFollower = $relationship['is_self']
            || $relationship['following'] === Follow::STATUS_ACCEPTED;

        $visible = ! $relationship['blocked_by_me']
            && $this->canSee($viewer, $target, $relationship['following']);

        $profile = [
            'id' => $target->uuid,
            'name' => $target->name,
            'username' => $target->username,
            'avatar_url' => $this->avatarFor($viewer, $target, $isFollower),
            'user_type' => $target->user_type,
            'is_verified' => $target->phone_verified_at !== null,

            'counts' => $this->counts($target),
            'relationship' => $relationship,

            // Whether the fields below are the real thing or withheld. The
            // client shows a "this account is private" panel on false rather
            // than an empty profile that looks broken.
            'is_visible' => $visible,
        ];

        if ($visible) {
            $profile += [
                'about' => $target->about,
                'phone' => $target->full_phone_number,
                'blood_group' => $target->blood_group,
                'date_of_birth' => $target->date_of_birth?->toDateString(),
                'last_seen_at' => $target->show_last_seen
                    ? $target->last_seen_at?->toIso8601String()
                    : null,
                'joined_at' => $target->created_at?->toIso8601String(),
            ];
        }

        return $profile;
    }


    /**
     * Whether a viewer gets the full profile.
     *
     * Public accounts are open to anyone signed in. Private ones open only to
     * accepted followers — and to the owner, who should never be locked out of
     * their own profile by their own setting.
     */
    private function canSee(User $viewer, User $target, string $followState): bool
    {
        return $viewer->id === $target->id
            || $followState === Follow::STATUS_ACCEPTED
            || ! $target->is_private;
    }

    /**
     * Switching from private to public clears the queue.
     *
     * Somebody who opens their account is saying "anyone may follow me", and
     * leaving old requests pending would contradict that — they would sit
     * unanswered while new followers walked straight in. Instagram does the
     * same thing, and users expect it.
     *
     * Returns how many were accepted, so the caller can say so.
     */
    public function acceptAllPendingFollows(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            $pending = Follow::where('followee_id', $user->id)
                ->pending()
                ->with('follower')
                ->get();

            if ($pending->isEmpty()) {
                return 0;
            }

            Follow::whereIn('id', $pending->pluck('id'))->update([
                'status' => Follow::STATUS_ACCEPTED,
                'responded_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($pending as $follow) {
                if ($follow->follower === null) {
                    continue;
                }

                $this->notifications->push(
                    to: $follow->follower,
                    actor: $user,
                    type: UserNotification::FOLLOW_ACCEPTED,
                    subject: $follow,
                    data: ['message' => $user->name.' accepted your follow request.'],
                );

                $this->notifications->resolveSubject($follow, $user);
            }

            return $pending->count();
        });
    }

    /**
     * Which picture a viewer gets.
     *
     * The alternate avatar exists precisely for this moment: a user who has set
     * one is saying "strangers see this instead". Honouring it here is the
     * whole point of having built it.
     *
     * Keyed on being an accepted follower, not on the profile being visible.
     * A public account is readable by anyone, but somebody who set a security
     * photo still meant it for everyone outside their circle — opening your
     * profile is not the same as withdrawing that.
     */
    private function avatarFor(User $viewer, User $target, bool $isFollower): ?string
    {
        if ($isFollower) {
            return $target->avatar_url;
        }

        return $target->use_alternate_avatar && $target->alternate_avatar_path !== null
            ? $target->alternate_avatar_url
            : $target->avatar_url;
    }

    /**
     * @return array<string, int>
     */
    public function counts(User $user): array
    {
        return [
            'followers' => Follow::where('followee_id', $user->id)->accepted()->count(),
            'following' => Follow::where('follower_id', $user->id)->accepted()->count(),
            'family' => FamilyMember::involving($user->id)->accepted()->count(),
        ];
    }

    /**
     * Both directions of the relationship between two users.
     *
     * @return array<string, mixed>
     */
    public function relationship(User $viewer, User $target): array
    {
        if ($viewer->id === $target->id) {
            return $this->selfRelationship();
        }

        $blockedByMe = $this->blocks->hasBlocked($viewer->id, $target->id);

        // Nothing survives a block, so there is no point querying
        // edges that severEverything() already removed.
        if ($blockedByMe) {
            return $this->blockedRelationship();
        }

        $outgoing = Follow::between($viewer->id, $target->id)->first();
        $incoming = Follow::between($target->id, $viewer->id)->first();

        $family = FamilyMember::query()
            ->where(function (Builder $q) use ($viewer, $target) {
                $q->where(fn (Builder $i) => $i->where('owner_id', $viewer->id)->where('member_id', $target->id))
                    ->orWhere(fn (Builder $i) => $i->where('owner_id', $target->id)->where('member_id', $viewer->id));
            })
            ->whereIn('status', [FamilyMember::STATUS_PENDING, FamilyMember::STATUS_ACCEPTED])
            ->first();

        return [
            'is_self' => false,
            'blocked_by_me' => false,
            'can_follow' => true,

            // Me -> them. "none" when there is no edge, or when a previous
            // request was declined: a declined row is kept to stop resend
            // spam, but the requester is never told it was refused.
            'following' => $this->edgeState($outgoing),

            // Them -> me. This is what puts "Follow back" on the button and
            // an Accept action in the feed.
            'followed_by' => $this->edgeState($incoming),

            // The pending request I need to answer, if any.
            'incoming_request_id' => $incoming?->isPending() ? $incoming->uuid : null,
            'outgoing_request_id' => $outgoing?->isPending() ? $outgoing->uuid : null,

            'family' => $this->familyState($viewer, $family),
            'family_id' => $family?->uuid,
            'family_relation' => $family?->relationFor($viewer),

            // A family invite only makes sense once they have accepted being
            // followed — otherwise it is a way to contact someone who has
            // already declined you.
            'can_invite_to_family' => $outgoing?->isAccepted() === true && $family === null,
        ];
    }

    /**
     * Everything a block collapses to. No edges, no family, no
     * actions except lifting it.
     *
     * @return array<string, mixed>
     */
    private function blockedRelationship(): array
    {
        return [
            'is_self' => false,
            'blocked_by_me' => true,
            'can_follow' => false,
            'following' => 'none',
            'followed_by' => 'none',
            'incoming_request_id' => null,
            'outgoing_request_id' => null,
            'family' => 'none',
            'family_id' => null,
            'family_relation' => null,
            'can_invite_to_family' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function selfRelationship(): array
    {
        return [
            'is_self' => true,
            'blocked_by_me' => false,
            'can_follow' => false,
            'following' => 'none',
            'followed_by' => 'none',
            'incoming_request_id' => null,
            'outgoing_request_id' => null,
            'family' => 'none',
            'family_id' => null,
            'family_relation' => null,
            'can_invite_to_family' => false,
        ];
    }

    /** none | pending | accepted — declined reads as none to the requester. */
    private function edgeState(?Follow $follow): string
    {
        return match ($follow?->status) {
            Follow::STATUS_PENDING => Follow::STATUS_PENDING,
            Follow::STATUS_ACCEPTED => Follow::STATUS_ACCEPTED,
            default => 'none',
        };
    }

    /** none | pending_out | pending_in | accepted */
    private function familyState(User $viewer, ?FamilyMember $family): string
    {
        if ($family === null) {
            return 'none';
        }

        if ($family->isAccepted()) {
            return 'accepted';
        }

        if (! $family->isPending()) {
            return 'none';
        }

        return $family->owner_id === $viewer->id ? 'pending_out' : 'pending_in';
    }

    /**
     * Relationship state for a whole list, in a fixed number of queries.
     *
     * Search results and follower lists both need a button state per row, and
     * calling relationship() per row would be three queries each.
     *
     * @param  Collection<int, User>  $users
     * @return list<array<string, mixed>>
     */
    public function summaries(User $viewer, Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $ids = $users->pluck('id')->all();

        $outgoing = Follow::where('follower_id', $viewer->id)
            ->whereIn('followee_id', $ids)->get()->keyBy('followee_id');

        $incoming = Follow::where('followee_id', $viewer->id)
            ->whereIn('follower_id', $ids)->get()->keyBy('follower_id');

        $family = FamilyMember::query()
            ->whereIn('status', [FamilyMember::STATUS_PENDING, FamilyMember::STATUS_ACCEPTED])
            ->where(function (Builder $q) use ($viewer, $ids) {
                $q->where(fn (Builder $i) => $i->where('owner_id', $viewer->id)->whereIn('member_id', $ids))
                    ->orWhere(fn (Builder $i) => $i->where('member_id', $viewer->id)->whereIn('owner_id', $ids));
            })
            ->get()
            ->keyBy(fn (FamilyMember $f) => $f->owner_id === $viewer->id ? $f->member_id : $f->owner_id);

        return $users->map(function (User $user) use ($viewer, $outgoing, $incoming, $family) {
            $out = $outgoing->get($user->id);
            $in = $incoming->get($user->id);
            $fam = $family->get($user->id);

            $isFollower = $viewer->id === $user->id || $out?->isAccepted() === true;
            $visible = $this->canSee($viewer, $user, $this->edgeState($out));

            return [
                'id' => $user->uuid,
                'name' => $user->name,
                'username' => $user->username,
                'avatar_url' => $this->avatarFor($viewer, $user, $isFollower),
                'user_type' => $user->user_type,
                'relationship' => [
                    'is_self' => $viewer->id === $user->id,
                    'blocked_by_me' => false,
                    'can_follow' => true,
                    'following' => $this->edgeState($out),
                    'followed_by' => $this->edgeState($in),
                    'incoming_request_id' => $in?->isPending() ? $in->uuid : null,
                    'outgoing_request_id' => $out?->isPending() ? $out->uuid : null,
                    'family' => $this->familyState($viewer, $fam),
                    'family_id' => $fam?->uuid,
                    'family_relation' => $fam?->relationFor($viewer),
                    'can_invite_to_family' => $out?->isAccepted() === true && $fam === null,
                ],
            ];
        })->values()->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Following
    |--------------------------------------------------------------------------
    */

    /**
     * Ask to follow somebody.
     *
     * Idempotent: asking again while pending returns the existing request
     * rather than sending a second notification.
     */
    public function follow(User $actor, User $target): Follow
    {
        if ($actor->id === $target->id) {
            $this->reject('You cannot follow yourself.');
        }

        if ($target->isBanned()) {
            $this->reject('That account is not available.');
        }

        // Deliberately the same wording either way round. Telling
        // somebody "you have been blocked" is the one thing a block
        // must never do.
        if ($this->walled($actor, $target)) {
            $this->reject('That account is not available.');
        }

        return DB::transaction(function () use ($actor, $target): Follow {
            $follow = Follow::between($actor->id, $target->id)->lockForUpdate()->first();

            if ($follow?->isAccepted()) {
                return $follow;
            }

            if ($follow?->isPending()) {
                return $follow;
            }

            if ($follow === null) {
                $follow = new Follow();
                $follow->follower_id = $actor->id;
                $follow->followee_id = $target->id;
            }

            // A public account is followed outright; a private one gets a
            // request to answer. This also covers a previously declined row
            // being asked again — allowed, and on a public account it now
            // simply succeeds, because the owner has since opened up.
            $instant = ! $target->is_private;

            $follow->status = $instant
                ? Follow::STATUS_ACCEPTED
                : Follow::STATUS_PENDING;
            $follow->requested_at = now();
            $follow->responded_at = $instant ? now() : null;
            $follow->save();

            $this->notifications->push(
                to: $target,
                actor: $actor,
                type: $instant
                    ? UserNotification::FOLLOW_STARTED
                    : UserNotification::FOLLOW_REQUESTED,
                subject: $follow,
                data: [
                    'message' => $instant
                        ? $actor->name.' started following you.'
                        : $actor->name.' wants to follow you.',
                ],
            );

            return $follow;
        });
    }

    /**
     * Withdraw my own edge — either an accepted follow or a pending request.
     *
     * Deletes rather than marking declined: this is the follower changing
     * their own mind, not the followee refusing. Any notification about the
     * request goes with it, so the other person is not left staring at an
     * Accept button for a request that no longer exists.
     */
    public function unfollow(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target) {
            $follow = Follow::between($actor->id, $target->id)->lockForUpdate()->first();

            if ($follow === null) {
                return;
            }

            $this->notifications->forgetSubject($follow);
            $follow->delete();
        });
    }

    /**
     * Answer a request somebody made to follow me.
     *
     * Only the followee may call this — checked by matching the request's
     * followee to the actor, so a leaked request id is not enough to accept
     * on somebody else's behalf.
     */
    public function respondToFollow(User $actor, string $uuid, bool $accept): Follow
    {
        return DB::transaction(function () use ($actor, $uuid, $accept): Follow {
            $follow = Follow::where('uuid', $uuid)->lockForUpdate()->first();

            if ($follow === null || $follow->followee_id !== $actor->id) {
                $this->reject('That request no longer exists.');
            }

            if (! $follow->isPending()) {
                // Already answered, possibly from the other screen. Not an
                // error — just report where things stand.
                return $follow;
            }

            $follow->status = $accept ? Follow::STATUS_ACCEPTED : Follow::STATUS_DECLINED;
            $follow->responded_at = now();
            $follow->save();

            if ($accept) {
                $this->notifications->push(
                    to: $follow->follower,
                    actor: $actor,
                    type: UserNotification::FOLLOW_ACCEPTED,
                    subject: $follow,
                    data: ['message' => $actor->name.' accepted your follow request.'],
                );
            }

            // The request notification has served its purpose either way.
            $this->notifications->resolveSubject($follow, $actor);

            return $follow;
        });
    }

    /** Remove somebody who follows me. Their edge, but my call. */
    public function removeFollower(User $actor, User $follower): void
    {
        DB::transaction(function () use ($actor, $follower) {
            $follow = Follow::between($follower->id, $actor->id)->lockForUpdate()->first();

            if ($follow === null) {
                return;
            }

            $this->notifications->forgetSubject($follow);
            $follow->delete();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function followers(User $viewer, User $target, int $limit = 50): array
    {
        $users = User::query()
            ->whereIn('id', Follow::where('followee_id', $target->id)->accepted()->select('follower_id'))
            ->orderBy('name')->limit($limit)->get();

        return ['results' => $this->summaries($viewer, $users)];
    }

    /**
     * @return array<string, mixed>
     */
    public function following(User $viewer, User $target, int $limit = 50): array
    {
        $users = User::query()
            ->whereIn('id', Follow::where('follower_id', $target->id)->accepted()->select('followee_id'))
            ->orderBy('name')->limit($limit)->get();

        return ['results' => $this->summaries($viewer, $users)];
    }

    /** Requests waiting on my answer. */
    public function pendingFollowRequests(User $actor, int $limit = 50): array
    {
        $follows = Follow::where('followee_id', $actor->id)
            ->pending()
            ->with('follower')
            ->orderByDesc('requested_at')
            ->limit($limit)
            ->get();

        $users = new Collection($follows->pluck('follower')->filter()->all());

        return ['results' => $this->summaries($actor, $users)];
    }

    /*
    |--------------------------------------------------------------------------
    | Family
    |--------------------------------------------------------------------------
    */

    /**
     * Invite an accepted follow into the family circle.
     *
     * The follow gate matters: family membership is the thing that will later
     * carry location and SOS visibility, and it should not be reachable by
     * anyone who has not already been let through the first door.
     */
    public function inviteToFamily(User $actor, User $target, ?string $relation = null): FamilyMember
    {
        if ($actor->id === $target->id) {
            $this->reject('You cannot add yourself.');
        }

        $follow = Follow::between($actor->id, $target->id)->first();

        if ($follow?->isAccepted() !== true) {
            $this->reject('Follow them first, and wait for them to accept.');
        }

        return DB::transaction(function () use ($actor, $target, $relation): FamilyMember {
            $existing = FamilyMember::query()
                ->where(function (Builder $q) use ($actor, $target) {
                    $q->where(fn (Builder $i) => $i->where('owner_id', $actor->id)->where('member_id', $target->id))
                        ->orWhere(fn (Builder $i) => $i->where('owner_id', $target->id)->where('member_id', $actor->id));
                })
                ->lockForUpdate()
                ->first();

            if ($existing !== null && in_array($existing->status, [
                FamilyMember::STATUS_PENDING, FamilyMember::STATUS_ACCEPTED,
            ], true)) {
                return $existing;
            }

            $family = $existing ?? new FamilyMember();
            $family->owner_id = $actor->id;
            $family->member_id = $target->id;
            $family->status = FamilyMember::STATUS_PENDING;
            $family->relation = $relation;
            $family->invited_at = now();
            $family->responded_at = null;
            $family->save();

            $this->notifications->push(
                to: $target,
                actor: $actor,
                type: UserNotification::FAMILY_INVITED,
                subject: $family,
                data: [
                    'message' => $actor->name.' wants to add you to their family.',
                    'relation' => $relation,
                ],
            );

            return $family;
        });
    }

    public function respondToFamily(
        User $actor,
        string $uuid,
        bool $accept,
        ?string $reverseRelation = null,
    ): FamilyMember {
        return DB::transaction(function () use ($actor, $uuid, $accept, $reverseRelation): FamilyMember {
            $family = FamilyMember::where('uuid', $uuid)->lockForUpdate()->first();

            if ($family === null || $family->member_id !== $actor->id) {
                $this->reject('That invite no longer exists.');
            }

            if (! $family->isPending()) {
                return $family;
            }

            $family->status = $accept ? FamilyMember::STATUS_ACCEPTED : FamilyMember::STATUS_DECLINED;
            $family->reverse_relation = $reverseRelation;
            $family->responded_at = now();
            $family->save();

            if ($accept) {
                $this->notifications->push(
                    to: $family->owner,
                    actor: $actor,
                    type: UserNotification::FAMILY_ACCEPTED,
                    subject: $family,
                    data: ['message' => $actor->name.' joined your family.'],
                );
            }

            $this->notifications->resolveSubject($family, $actor);

            return $family;
        });
    }

    /**
     * Leave or remove a family link. Either side may do this.
     *
     * Marked removed rather than deleted, so the history of who was once in a
     * circle survives — which will matter the first time somebody asks why an
     * old SOS reached a person who is no longer family.
     */
    public function removeFamily(User $actor, string $uuid): void
    {
        DB::transaction(function () use ($actor, $uuid) {
            $family = FamilyMember::where('uuid', $uuid)->lockForUpdate()->first();

            if ($family === null) {
                return;
            }

            if ($family->owner_id !== $actor->id && $family->member_id !== $actor->id) {
                $this->reject('That is not yours to remove.');
            }

            $family->status = FamilyMember::STATUS_REMOVED;
            $family->responded_at = now();
            $family->save();

            $this->notifications->forgetSubject($family);
        });
    }

    /**
     * Everyone in this user's family, from both ends of the table.
     *
     * @return array<string, mixed>
     */
    public function family(User $user): array
    {
        $rows = FamilyMember::involving($user->id)
            ->accepted()
            ->with(['owner', 'member'])
            ->get();

        // Build the same per-person shape every other list uses, so the
        // family tab can render the identical row widget — including its
        // follow button — instead of needing a parallel one.
        $others = new Collection(
            $rows->map(fn (FamilyMember $row) => $row->counterpartFor($user))->all()
        );

        $summaries = collect($this->summaries($user, $others))->keyBy('id');

        $members = $rows->map(function (FamilyMember $row) use ($user, $summaries) {
            $other = $row->counterpartFor($user);
            $summary = $summaries->get($other->uuid, []);

            return $summary + [
                'family_id' => $row->uuid,
                'id' => $other->uuid,
                'name' => $other->name,
                'username' => $other->username,
                'avatar_url' => $other->avatar_url,
                'user_type' => $other->user_type,
            ] + [
                'relation' => $row->relationFor($user),

                // Placeholder until check-in visibility across a circle lands.
                // Deliberately not invented: the home strip shows a neutral
                // label rather than claiming everyone is safe.
                'status' => null,
                'last_seen_at' => $other->show_last_seen
                    ? $other->last_seen_at?->toIso8601String()
                    : null,
            ];
        })->sortBy('name')->values()->all();

        $pendingOut = FamilyMember::where('owner_id', $user->id)
            ->where('status', FamilyMember::STATUS_PENDING)->count();

        $pendingIn = FamilyMember::where('member_id', $user->id)
            ->where('status', FamilyMember::STATUS_PENDING)->count();

        return [
            'members' => $members,
            'total' => count($members),
            'pending_sent' => $pendingOut,
            'pending_received' => $pendingIn,
        ];
    }

    /**
     * @throws ValidationException
     */
    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['relationship' => [$message]]);
    }
}
