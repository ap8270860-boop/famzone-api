<?php

namespace App\Services\Chat;

use App\Events\Chat\ConversationClosed;
use App\Models\Block;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\FamilyMember;
use App\Models\Follow;
use App\Models\Message;
use App\Models\User;
use App\Services\Social\RelationshipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Group conversations.
 *
 * Everything that is true of a group and not of a direct thread lives here,
 * which is most of what makes a group different: it has a name and a picture,
 * it is made rather than found, and it cannot be reduced to "the other
 * person".
 *
 * ChatService still owns sending, history and receipts — a group message is
 * an ordinary message in a conversation with more than two people in it, and
 * duplicating that pipeline would mean two send paths that drift apart.
 */
class GroupService
{
    /** Nobody in this app is organising a thousand people. */
    public const MAX_MEMBERS = 100;

    /** Who can be added: everyone connected, or family only. */
    public const SCOPE_CONNECTIONS = 'connections';
    public const SCOPE_FAMILY = 'family';

    public function __construct(
        private readonly ChatService $chat,
        private readonly RelationshipService $relationships,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Who can be added
    |--------------------------------------------------------------------------
    */

    /**
     * The people this person may put in a group.
     *
     * Enforced on the server, not merely offered by the picker. A member list
     * that arrives from a client is a list of user ids somebody could have
     * typed — without this check, anybody could be pulled into a group by a
     * stranger who guessed a uuid.
     *
     * @return Collection<int, User>
     */
    public function candidateUsers(User $me, string $scope): Collection
    {
        $ids = $scope === self::SCOPE_FAMILY
            ? $this->familyIds($me)
            : $this->connectionIds($me);

        $ids = array_values(array_diff($ids, [$me->id]));

        if ($ids === []) {
            return new Collection();
        }

        return User::query()
            ->whereIn('id', $ids)
            /*
             | A wall in either direction takes somebody off the list. Being
             | able to add a person you blocked into a room with you is not a
             | feature.
             |
             | wallIds() is a subquery rather than a list of ids, so it goes
             | to the database here instead of into array_diff.
             */
            ->whereNotIn('id', Block::wallIds($me->id))
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function candidates(User $me, string $scope): array
    {
        $users = $this->candidateUsers($me, $scope);

        return [
            'scope' => $scope,
            'total' => $users->count(),
            'people' => $this->relationships->summaries($me, $users),
        ];
    }

    /**
     * Everyone I follow, plus everyone who follows me.
     *
     * Both directions on purpose: a group is a room, not a broadcast, and
     * "people I have a connection with" is the honest reading of who you
     * should be able to put in one.
     *
     * @return array<int, int>
     */
    private function connectionIds(User $me): array
    {
        $following = Follow::query()
            ->where('follower_id', $me->id)
            ->where('status', Follow::STATUS_ACCEPTED)
            ->pluck('followee_id');

        $followers = Follow::query()
            ->where('followee_id', $me->id)
            ->where('status', Follow::STATUS_ACCEPTED)
            ->pluck('follower_id');

        // Family too: somebody can be family without either of you following
        // the other, and leaving them out of "New group" would be strange.
        return array_values(array_unique(array_merge(
            $following->all(),
            $followers->all(),
            $this->familyIds($me),
        )));
    }

    /**
     * @return array<int, int>
     */
    private function familyIds(User $me): array
    {
        return FamilyMember::query()
            ->where('status', FamilyMember::STATUS_ACCEPTED)
            ->where(fn (Builder $q) => $q
                ->where('owner_id', $me->id)
                ->orWhere('member_id', $me->id))
            ->get()
            ->map(fn (FamilyMember $link) => $link->owner_id === $me->id
                ? $link->member_id
                : $link->owner_id)
            ->unique()
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Making one
    |--------------------------------------------------------------------------
    */

    /**
     * Create a group.
     *
     * @param  array<int, string>  $memberUuids
     */
    public function create(
        User $me,
        string $title,
        array $memberUuids,
        string $scope = self::SCOPE_CONNECTIONS,
        ?UploadedFile $avatar = null,
    ): Conversation {
        $allowed = $this->candidateUsers($me, $scope)->keyBy('uuid');

        $members = [];

        foreach (array_unique($memberUuids) as $uuid) {
            $user = $allowed->get($uuid);

            /*
             | 422 rather than skipping quietly.
             |
             | A picker that offered somebody the server then drops leaves a
             | group missing a person nobody notices is missing, which is
             | worse than refusing the whole thing and saying so.
             */
            abort_if(
                $user === null,
                422,
                'One of those people cannot be added to a group.',
            );

            $members[] = $user;
        }

        abort_if($members === [], 422, 'Choose at least one person.');

        abort_if(
            count($members) + 1 > self::MAX_MEMBERS,
            422,
            'A group can hold '.self::MAX_MEMBERS.' people.',
        );

        $path = $avatar === null ? null : $this->storeAvatar($me, $avatar);

        $conversation = DB::transaction(function () use ($me, $title, $members, $path) {
            $conversation = new Conversation([
                'type' => Conversation::TYPE_GROUP,
                'title' => $title,
            ]);

            // pair_key stays null: a group has no canonical pair, which is
            // why that unique column was made nullable before groups existed.
            $conversation->avatar_path = $path;
            $conversation->created_by_id = $me->id;
            $conversation->save();

            $this->addParticipant($conversation, $me, ConversationParticipant::ROLE_ADMIN);

            foreach ($members as $member) {
                $this->addParticipant($conversation, $member);
            }

            return $conversation;
        });

        /*
         | The first thing in the thread.
         |
         | An empty group is indistinguishable from a broken one — the inbox
         | hides threads with no last_message_at, so without this the group
         | would not appear in anybody's list until somebody spoke.
         */
        $this->systemMessage($conversation, $me, $me->name.' created this group');

        return $conversation->fresh(['participants.user']);
    }

    private function addParticipant(
        Conversation $conversation,
        User $user,
        string $role = ConversationParticipant::ROLE_MEMBER,
    ): void {
        $participant = new ConversationParticipant([
            // No requests in a group. You are in it because a member you are
            // connected with put you in it, and a Requests tab for that would
            // be a decision nobody wants to make one person at a time.
            'state' => ConversationParticipant::STATE_ACCEPTED,
        ]);

        // Assigned as properties, not through fill: mass assignment drops
        // anything not marked Fillable and does it silently.
        $participant->conversation_id = $conversation->id;
        $participant->user_id = $user->id;
        $participant->role = $role;
        $participant->joined_at = now();
        $participant->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Changing one
    |--------------------------------------------------------------------------
    */

    /**
     * Rename it, or give it a different picture.
     *
     * Any member, not only an admin. A group's name and face are how the room
     * describes itself, and in a family group the person who happened to
     * create it is rarely the person who notices the name is wrong.
     * Membership is the check that matters; being first is not a rank.
     */
    public function update(
        User $me,
        Conversation $conversation,
        ?string $title = null,
        ?UploadedFile $avatar = null,
    ): Conversation {
        $participant = $this->chat->participantOrFail($conversation, $me);

        abort_if($participant->hasLeft(), 403, 'You are no longer in this group.');

        $changes = [];
        $notes = [];
        $previous = $conversation->avatar_path;

        if ($title !== null && $title !== '' && $title !== $conversation->title) {
            $changes['title'] = $title;
            $notes[] = $me->name.' changed the group name to "'.$title.'"';
        }

        if ($avatar !== null) {
            $changes['avatar_path'] = $this->storeAvatar($me, $avatar);
            $notes[] = $me->name.' changed the group photo';
        }

        // Nothing actually changed — a save and two system messages saying so
        // would be worse than doing nothing.
        if ($changes === []) {
            return $conversation;
        }

        $conversation->forceFill($changes)->save();

        /*
         | The old picture goes after the new one is committed, not before.
         |
         | The other order leaves a group with no photo whenever the write
         | fails, and the bytes are the only copy.
         */
        if ($avatar !== null && filled($previous)) {
            try {
                Storage::disk(config('filesystems.default'))->delete($previous);
            } catch (\Throwable) {
                // A file that outlives its row is untidy, not broken.
            }
        }

        foreach ($notes as $note) {
            $this->systemMessage($conversation, $me, $note);
        }

        return $conversation->fresh(['participants.user']);
    }

    /**
     * Take somebody out of the group.
     *
     * Admins only, unlike renaming. Removing a person is done *to* them
     * rather than to the room, and that is the line: anybody may change what
     * the group is called, only an admin may change who is in it.
     */
    public function removeMember(User $me, Conversation $conversation, User $target): void
    {
        $mine = $this->chat->participantOrFail($conversation, $me);

        abort_unless(
            $mine->isAdmin() && ! $mine->hasLeft(),
            403,
            'Only a group admin can remove people.',
        );

        // Leaving is a different act, with a different system message and a
        // different meaning to everybody watching.
        abort_if($target->id === $me->id, 422, 'Use Leave group instead.');

        $theirs = $conversation->participants()
            ->where('user_id', $target->id)
            ->first();

        abort_if(
            $theirs === null || $theirs->left_at !== null,
            404,
            'They are not in this group.',
        );

        $theirs->forceFill(['left_at' => now()])->save();

        $this->systemMessage($conversation, $me, $me->name.' removed '.$target->name);

        /*
         | And tell them to let go of the channel.
         |
         | The system message above only reaches people still in the room, so
         | without this the person just removed keeps a live subscription to a
         | conversation they are no longer part of until they next reload.
         */
        ConversationClosed::dispatch($conversation, $target);
    }

    /*
    |--------------------------------------------------------------------------
    | Leaving
    |--------------------------------------------------------------------------
    */

    /**
     * Leave a group.
     *
     * Different from leaving a direct thread, where the row is kept so a
     * later message reopens it. Here leaving is a fact the room can see, and
     * nothing pulls you back in on its own.
     */
    public function leave(User $me, Conversation $conversation): void
    {
        $participant = $this->chat->participantOrFail($conversation, $me);

        if ($participant->hasLeft()) {
            return;
        }

        $participant->forceFill(['left_at' => now()])->save();

        $this->systemMessage($conversation, $me, $me->name.' left');
    }

    /*
    |--------------------------------------------------------------------------
    | System messages
    |--------------------------------------------------------------------------
    */

    /**
     * A line in the thread that nobody typed.
     *
     * Its own small insert rather than a trip through ChatService::send():
     * that path checks whether the sender may message the recipient, caps
     * message requests and bumps unread counts, none of which should apply to
     * "Faisal left". This writes the row, moves the conversation's pointers,
     * and announces it — and deliberately does not touch anybody's unread
     * count, so a badge never appears for somebody leaving.
     */
    public function systemMessage(Conversation $conversation, User $actor, string $body): Message
    {
        $message = DB::transaction(function () use ($conversation, $actor, $body) {
            $locked = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();

            $seq = $locked->last_seq + 1;

            $message = new Message([
                'type' => Message::TYPE_SYSTEM,
                'body' => $body,
                'client_uuid' => (string) Str::uuid7(),
            ]);

            $message->conversation_id = $conversation->id;
            $message->sender_id = $actor->id;
            $message->seq = $seq;
            $message->save();

            $locked->forceFill([
                'last_seq' => $seq,
                'last_message_id' => $message->id,
                'last_message_at' => $message->created_at,
            ])->save();

            return $message;
        });

        $this->chat->announce($conversation, $message->fresh(['sender']));

        return $message;
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    */

    /**
     * The group half of a conversation payload.
     *
     * @return array<string, mixed>
     */
    public function present(User $me, Conversation $conversation, bool $withMembers = false): array
    {
        $active = $conversation->participants->whereNull('left_at');
        $mine = $active->firstWhere('user_id', $me->id);
        $others = $active->where('user_id', '!=', $me->id);

        return [
            'title' => $conversation->title,
            'avatar_url' => $this->avatarUrl($conversation),
            'members_count' => $active->count(),
            'is_admin' => $mine?->role === ConversationParticipant::ROLE_ADMIN,
            'created_by_me' => $conversation->created_by_id === $me->id,

            /*
             | The group's ticks, in the same shape the direct thread reports
             | them, so the client's existing logic works unchanged.
             |
             | The minimum across everybody else, which is what makes a group
             | message blue only once the last person has read it — a
             | "delivered" that meant "somebody, somewhere" would be worse
             | than no tick at all.
             */
            'last_read_seq' => (int) ($others->min(fn (ConversationParticipant $p) => $p->user->show_read_receipts
                ? $p->last_read_seq
                : $p->last_delivered_seq) ?? 0),
            'last_delivered_seq' => (int) ($others->min('last_delivered_seq') ?? 0),

            // Only on the single-conversation and members endpoints. An inbox
            // page of twenty groups has no business carrying every member of
            // each of them.
            'members' => $withMembers
                ? $this->relationships->summaries(
                    $me,
                    $active->map(fn (ConversationParticipant $p) => $p->user)->values(),
                )
                : null,

            'roles' => $withMembers
                ? $active->mapWithKeys(fn (ConversationParticipant $p) => [
                    $p->user->uuid => $p->role,
                ])->all()
                : null,
        ];
    }

    public function avatarUrl(Conversation $conversation): ?string
    {
        if (blank($conversation->avatar_path)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'api.v1.media.group',
            now()->addHours(User::MEDIA_LINK_HOURS),
            ['uuid' => $conversation->uuid],
        );
    }

    private function storeAvatar(User $me, UploadedFile $file): string
    {
        $disk = Storage::disk(config('filesystems.default'));

        $path = "groups/{$me->uuid}/".Str::uuid7().'.'.($file->extension() ?: 'jpg');

        $disk->putFileAs(dirname($path), $file, basename($path), [
            'visibility' => 'private',
        ]);

        return $path;
    }
}
