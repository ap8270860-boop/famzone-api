<?php

namespace App\Services\Chat;

use App\Models\Block;
use App\Models\Follow;
use App\Models\User;

/**
 * Online, and last seen.
 *
 * Deliberately a heartbeat rather than a presence channel. A global presence
 * channel broadcasts every join and every leave to every member: with a
 * thousand signed-in users that is a million frames of pure churn, and it
 * grows with the square of the user count. Being online is a fact about one
 * person, not a room they are standing in.
 *
 * The presence channel that arrives in a later phase answers a different and
 * much smaller question — who is looking at this one thread right now — where
 * the membership is two people and the churn is nothing.
 */
class PresenceService
{
    /**
     * How long after a heartbeat somebody still counts as online.
     *
     * Wider than the client's ping interval on purpose, so one dropped
     * request does not flicker somebody offline and back.
     */
    public const ONLINE_WINDOW_SECONDS = 75;

    /** What the client should use between pings. */
    public const PING_INTERVAL_SECONDS = 45;

    /**
     * Record that someone is here.
     *
     * forceFill on a single column rather than touch() or a full save: this
     * runs every 45 seconds for every foregrounded app in the system, and it
     * has no business writing updated_at or firing model events.
     *
     * @return array<string, mixed>
     */
    public function ping(User $user): array
    {
        $now = now();

        User::whereKey($user->id)->update(['last_seen_at' => $now]);

        return [
            'last_seen_at' => $now->toIso8601String(),
            'ping_after' => self::PING_INTERVAL_SECONDS,
        ];
    }

    /**
     * What one person is allowed to know about another's presence.
     *
     * Three separate refusals, and they are not the same refusal:
     *
     *  - a block hides everything, in both directions;
     *  - show_online_status off hides the live dot;
     *  - show_last_seen off hides the timestamp.
     *
     * Someone can reasonably want "online" visible without publishing the
     * exact minute they last put their phone down, so the two settings stay
     * independent.
     *
     * Absent fields are omitted rather than sent as false or as a stale
     * timestamp. The client already treats a missing value as "show no
     * presence line", which is the honest rendering of a fact the server
     * declined to state.
     *
     * @return array<string, mixed>
     */
    public function presenceFor(User $viewer, User $person): array
    {
        if ($viewer->id !== $person->id && Block::between($viewer->id, $person->id)->exists()) {
            return ['online' => null, 'last_seen_at' => null];
        }

        return [
            'online' => $person->show_online_status ? $this->isOnline($person) : null,
            'last_seen_at' => $person->show_last_seen
                ? $person->last_seen_at?->toIso8601String()
                : null,
        ];
    }

    public function isOnline(User $person): bool
    {
        return $person->last_seen_at !== null
            && $person->last_seen_at->gt(now()->subSeconds(self::ONLINE_WINDOW_SECONDS));
    }

    /**
     * Whether the viewer is close enough to this person to be shown presence
     * at all.
     *
     * Not used by the chat header — being in a conversation with somebody is
     * already reason enough — but the profile screen needs it, and putting
     * the rule here keeps the two from drifting.
     */
    public function maySeePresence(User $viewer, User $person): bool
    {
        if ($viewer->id === $person->id) {
            return true;
        }

        if (Block::between($viewer->id, $person->id)->exists()) {
            return false;
        }

        if (! $person->is_private) {
            return true;
        }

        return Follow::between($viewer->id, $person->id)->accepted()->exists();
    }
}
