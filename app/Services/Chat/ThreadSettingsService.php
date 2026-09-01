<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageHide;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * What one person has decided about a thread they are in.
 *
 * Pinning it to the top of their list, silencing it, marking it unread,
 * emptying it. None of it is broadcast and none of it is visible to the other
 * person — every one of these writes a column on the caller's own
 * participant row and nothing else.
 *
 * Separate from ChatService because that service is about the conversation
 * itself, which both people share. This one is about one person's view of it.
 */
class ThreadSettingsService
{
    /**
     * Muting with no end date.
     *
     * A far-future timestamp rather than a nullable "forever" flag, so
     * `muted_until > now()` stays the single question every read has to ask.
     * One column, one comparison, no special cases.
     */
    private const FOREVER_YEARS = 100;

    public function __construct(private readonly ChatService $chat)
    {
    }

    /**
     * Pin the thread to the top of this person's inbox, or unpin it.
     *
     * Returns the new state so the client repaints from the response rather
     * than guessing what the toggle did.
     */
    public function togglePin(User $me, Conversation $conversation): bool
    {
        $participant = $this->chat->participantOrFail($conversation, $me);

        $pinned = $participant->pinned_at === null;

        $participant->forceFill([
            'pinned_at' => $pinned ? now() : null,
        ])->save();

        return $pinned;
    }

    /**
     * Silence the thread, or let it speak again.
     *
     * [$hours] of null means indefinitely. Muting is about notifications
     * only: messages still arrive, the thread still counts as unread, and
     * the other person is told nothing.
     */
    public function mute(User $me, Conversation $conversation, bool $muted, ?int $hours = null): ?Carbon
    {
        $participant = $this->chat->participantOrFail($conversation, $me);

        $until = ! $muted
            ? null
            : ($hours === null
                ? now()->addYears(self::FOREVER_YEARS)
                : now()->addHours(max(1, min(24 * 365, $hours))));

        $participant->forceFill(['muted_until' => $until])->save();

        return $until;
    }

    /**
     * Make a thread look unread again.
     *
     * Only a flag. The read watermark is deliberately left where it is:
     * moving it back would turn the other person's blue ticks grey, which
     * tells them something untrue about what you have seen and is in any
     * case none of their business.
     *
     * Cleared the next time this person actually reads the thread — see
     * ReceiptService, which drops it as part of the same write that moves
     * the watermark.
     */
    public function markUnread(User $me, Conversation $conversation): void
    {
        $participant = $this->chat->participantOrFail($conversation, $me);

        $participant->forceFill(['marked_unread' => true])->save();
    }

    /**
     * Empty the thread, for this person only.
     *
     * Every message hidden, one row each, exactly as if they had deleted
     * each one for themselves — because that is what this is. The other
     * person's copy of the conversation is untouched and they are told
     * nothing, which is the difference between clearing a chat and deleting
     * it.
     *
     * Chunked because a long thread is thousands of rows and a single
     * insert of all of them is a packet nobody sized for.
     *
     * @return int how many messages were hidden
     */
    public function clear(User $me, Conversation $conversation): int
    {
        $this->chat->participantOrFail($conversation, $me);

        $now = now();
        $hidden = 0;

        $conversation->messages()
            ->withTrashed()
            ->select('messages.id')
            ->orderBy('messages.id')
            ->chunk(500, function ($messages) use ($me, $now, &$hidden) {
                $rows = $messages->map(fn (Message $message) => [
                    'message_id' => $message->id,
                    'user_id' => $me->id,
                    'created_at' => $now,
                ])->all();

                // insertOrIgnore, so re-clearing a thread that is already
                // half hidden is not a unique-key error. Clearing twice is
                // the same as clearing once.
                $hidden += MessageHide::insertOrIgnore($rows);
            });

        /*
         | An emptied thread has nothing left to be unread about.
         |
         | Not touching the read watermark, though: it is what the other
         | person's ticks are drawn from, and clearing your own screen must
         | not tell them you un-read their messages.
         */
        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $me->id)
            ->update([
                'unread_count' => 0,
                'marked_unread' => false,
                'updated_at' => now(),
            ]);

        return $hidden;
    }
}
