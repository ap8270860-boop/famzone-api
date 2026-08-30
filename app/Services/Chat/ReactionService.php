<?php

namespace App\Services\Chat;

use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Emoji on messages.
 *
 * One per person per message, enforced by a unique index. Reacting again with
 * a different emoji replaces the first; reacting with the same one removes
 * it. That is what people already expect from every other chat app, and it
 * means a message can never collect a wall of reactions from one person.
 */
class ReactionService
{
    /**
     * The row of emoji the long-press menu offers.
     *
     * A short, fixed list rather than a full picker. Six covers almost every
     * reaction anybody sends, and a grid of two thousand turns a one-tap
     * gesture into a search.
     */
    public const QUICK = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

    /**
     * Longest emoji we will store.
     *
     * A family with skin tones and joiners runs to seven code points. Beyond
     * that it is not an emoji, it is somebody testing what fits in a column.
     */
    public const MAX_LENGTH = 32;

    /**
     * Add, change or remove somebody's reaction.
     *
     * Idempotent in the way a toggle needs to be: sending the same emoji
     * twice leaves the message with none, which is exactly what tapping the
     * same one twice should do.
     */
    public function react(User $user, Message $message, ?string $emoji): Message
    {
        DB::transaction(function () use ($user, $message, $emoji) {
            $existing = MessageReaction::where('message_id', $message->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            // No emoji, or the same one again: take it off.
            if ($emoji === null || $existing?->emoji === $emoji) {
                $existing?->delete();

                return;
            }

            if ($existing !== null) {
                $existing->forceFill(['emoji' => $emoji])->save();

                return;
            }

            $reaction = new MessageReaction(['emoji' => $emoji]);

            $reaction->message_id = $message->id;
            $reaction->user_id = $user->id;
            $reaction->save();
        });

        return $message->fresh(['reactions.user:id,uuid']);
    }

    /**
     * Group a message's reactions for display.
     *
     * Grouped by emoji with the reactors' public ids, so a client can render
     * "👍 3" and decide on its own whether one of those three is the person
     * holding the phone. That is why no viewer is needed here, and why the
     * same payload can be broadcast to everybody.
     *
     * @return array<int, array<string, mixed>>
     */
    public function present(?Message $message): array
    {
        if ($message === null) {
            return [];
        }

        return $message->reactions
            ->groupBy('emoji')
            ->map(fn ($group, $emoji) => [
                'emoji' => $emoji,
                'count' => $group->count(),
                'user_ids' => $group
                    ->map(fn (MessageReaction $r) => $r->user?->uuid)
                    ->filter()
                    ->values()
                    ->all(),
            ])
            // Most-reacted first, so the pills do not reshuffle every time
            // somebody adds one.
            ->sortByDesc('count')
            ->values()
            ->all();
    }
}
