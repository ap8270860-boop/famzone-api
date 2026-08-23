<?php

namespace App\Services\Social;

use App\Models\FamilyMember;
use App\Models\Follow;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The in-app notification feed.
 *
 * The design decision worth stating: a notification stores what happened, and
 * never what has since been decided. Whether an Accept button still shows is
 * resolved at read time from the follow or family row the notification points
 * at.
 *
 * Store the answer in the notification and you get the classic bug — accept a
 * request from the profile screen, open the feed, and there is the Accept
 * button again, offering to accept something already accepted. Deriving it
 * means the two screens cannot disagree, because there is only one fact.
 */
class NotificationService
{
    public const PER_PAGE = 30;

    /**
     * Record something that happened, for one recipient.
     *
     * Never notifies somebody about their own action — an actor and recipient
     * that match means a bug upstream, and a self-notification is noise the
     * user cannot act on.
     */
    public function push(
        User $to,
        ?User $actor,
        string $type,
        ?Model $subject = null,
        array $data = [],
    ): ?UserNotification {
        if ($actor !== null && $actor->id === $to->id) {
            return null;
        }

        $notification = new UserNotification();
        $notification->user_id = $to->id;
        $notification->actor_id = $actor?->id;
        $notification->type = $type;
        $notification->subject_type = $subject === null ? null : $subject::class;
        $notification->subject_id = $subject?->getKey();
        $notification->data = $data;
        $notification->save();

        // Push delivery hooks in here once FCM lands. Deliberately not a queue
        // job yet: there is nothing to deliver, and an empty job is harder to
        // reason about than an obvious seam.

        return $notification;
    }

    /**
     * The feed, with each entry's live action state resolved.
     *
     * @return array<string, mixed>
     */
    public function feed(User $user, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $perPage = max(1, min(50, $perPage));

        $query = UserNotification::where('user_id', $user->id)
            ->with('actor')
            ->newestFirst();

        $total = (clone $query)->count();

        $rows = $query->forPage($page, $perPage)->get();

        $states = $this->resolveStates($rows);

        return [
            'unread' => $this->unreadCount($user),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $page * $perPage < $total,
            'notifications' => $rows->map(
                fn (UserNotification $n) => $this->present($n, $states)
            )->values()->all(),
        ];
    }

    public function unreadCount(User $user): int
    {
        return UserNotification::where('user_id', $user->id)->unread()->count();
    }

    /**
     * Load every subject referenced by a page of notifications, in one query
     * per type rather than one per row.
     *
     * @param  Collection<int, UserNotification>  $rows
     * @return array<string, Model>
     */
    private function resolveStates(Collection $rows): array
    {
        $byType = $rows->filter(fn (UserNotification $n) => $n->subject_id !== null)
            ->groupBy('subject_type');

        $resolved = [];

        foreach ($byType as $type => $group) {
            if (! is_string($type) || ! class_exists($type)) {
                continue;
            }

            /** @var class-string<Model> $type */
            $models = $type::query()
                ->whereIn('id', $group->pluck('subject_id')->unique()->all())
                ->get();

            foreach ($models as $model) {
                $resolved[$type.':'.$model->getKey()] = $model;
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, Model>  $states
     * @return array<string, mixed>
     */
    private function present(UserNotification $n, array $states): array
    {
        $subject = $n->subject_id === null
            ? null
            : ($states[$n->subject_type.':'.$n->subject_id] ?? null);

        return [
            'id' => $n->uuid,
            'type' => $n->type,
            'message' => $n->data['message'] ?? $this->fallbackMessage($n),
            'created_at' => $n->created_at?->toIso8601String(),
            'read' => ! $n->isUnread(),

            'actor' => $n->actor === null ? null : [
                'id' => $n->actor->uuid,
                'name' => $n->actor->name,
                'username' => $n->actor->username,
                // The feed is people you have a live relationship with, so the
                // real avatar is right here — the alternate exists for
                // strangers, and somebody who has asked to follow you is not
                // anonymous.
                'avatar_url' => $n->actor->avatar_url,
            ],

            // What the row can still do, worked out from the subject rather
            // than remembered. Null once nothing is actionable.
            'action' => $this->actionFor($n, $subject),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function actionFor(UserNotification $n, ?Model $subject): ?array
    {
        if ($subject instanceof Follow) {
            return $n->type === UserNotification::FOLLOW_REQUESTED && $subject->isPending()
                ? [
                    'kind' => 'follow_request',
                    'request_id' => $subject->uuid,
                    'accept' => true,
                    'decline' => true,
                ]
                : null;
        }

        if ($subject instanceof FamilyMember) {
            return $n->type === UserNotification::FAMILY_INVITED && $subject->isPending()
                ? [
                    'kind' => 'family_invite',
                    'invite_id' => $subject->uuid,
                    'relation' => $subject->relation,
                    'accept' => true,
                    'decline' => true,
                ]
                : null;
        }

        return null;
    }

    private function fallbackMessage(UserNotification $n): string
    {
        $who = $n->actor?->name ?? 'Someone';

        return match ($n->type) {
            UserNotification::FOLLOW_REQUESTED => $who.' wants to follow you.',
            UserNotification::FOLLOW_ACCEPTED => $who.' accepted your follow request.',
            UserNotification::FAMILY_INVITED => $who.' wants to add you to their family.',
            UserNotification::FAMILY_ACCEPTED => $who.' joined your family.',
            default => 'You have a new notification.',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Read state
    |--------------------------------------------------------------------------
    */

    public function markRead(User $user, string $uuid): void
    {
        UserNotification::where('user_id', $user->id)
            ->where('uuid', $uuid)
            ->unread()
            ->update(['read_at' => now()]);
    }

    public function markAllRead(User $user): int
    {
        return UserNotification::where('user_id', $user->id)
            ->unread()
            ->update(['read_at' => now()]);
    }

    /*
    |--------------------------------------------------------------------------
    | Subject lifecycle
    |--------------------------------------------------------------------------
    */

    /**
     * A request has been answered — mark its notification read so the badge
     * clears, but keep the row. "X wanted to follow you" is still a true
     * record of something that happened.
     */
    public function resolveSubject(Model $subject, User $actor): void
    {
        UserNotification::forSubject($subject::class, $subject->getKey())
            ->where('user_id', $actor->id)
            ->unread()
            ->update(['read_at' => now()]);
    }

    /**
     * The subject is gone — an unfollow, a cancelled request, a removed family
     * link. Delete the notifications pointing at it, because a feed entry whose
     * subject no longer exists is a dead end the user can neither act on nor
     * dismiss.
     */
    public function forgetSubject(Model $subject): void
    {
        UserNotification::forSubject($subject::class, $subject->getKey())->delete();
    }
}
