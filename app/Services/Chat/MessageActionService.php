<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageStar;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Starring, pinning and forwarding.
 *
 * Grouped because they all act on an existing message rather than creating a
 * conversation, but they are three different kinds of thing: a star is
 * private, a pin is shared by the thread, and a forward makes a new message
 * somewhere else entirely.
 */
class MessageActionService
{
    public const STARRED_PER_PAGE = 30;

    public function __construct(private readonly ChatService $chat)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | Starring
    |--------------------------------------------------------------------------
    */

    /**
     * Star or unstar, whichever it is not already.
     *
     * Returns the new state so a client can repaint from one response rather
     * than guessing what the toggle did.
     */
    public function toggleStar(User $user, Message $message): bool
    {
        $existing = MessageStar::where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return false;
        }

        try {
            $star = new MessageStar();

            $star->message_id = $message->id;
            $star->user_id = $user->id;
            $star->created_at = now();
            $star->save();
        } catch (QueryException $e) {
            // Two taps in flight at once. The unique index caught the second,
            // and the answer is still "starred".
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }
        }

        return true;
    }

    /**
     * Which of these messages this person has starred.
     *
     * Fetched as a set for a whole page rather than per message: a scrollback
     * of forty would otherwise be forty queries for a boolean.
     *
     * @param  array<int, int>  $messageIds
     * @return array<int, true>
     */
    public function starredAmong(User $user, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        return array_fill_keys(
            MessageStar::where('user_id', $user->id)
                ->whereIn('message_id', $messageIds)
                ->pluck('message_id')
                ->all(),
            true,
        );
    }

    /**
     * Everything this person has starred, newest first.
     *
     * @return array<string, mixed>
     */
    public function starred(User $user, int $page = 1, int $perPage = self::STARRED_PER_PAGE): array
    {
        $perPage = max(1, min(50, $perPage));

        $query = Message::query()
            ->whereIn('id', MessageStar::where('user_id', $user->id)->select('message_id'))
            /*
             | Only from threads they are still in.
             |
             | Leaving a conversation, or being blocked out of one, must take
             | its messages out of here too — otherwise the Starred screen
             | becomes a way to keep reading a conversation you no longer have
             | access to.
             */
            ->whereIn('conversation_id', Conversation::query()
                ->forUser($user->id)
                ->select('id'))
            ->with([
                'sender:id,uuid',
                'attachment',
                'replyTo.sender:id,uuid',
                'reactions.user:id,uuid',
                'conversation.participants.user',
            ])
            ->orderByDesc('id');

        $total = (clone $query)->count();
        $messages = $query->forPage($page, $perPage)->get();

        $starred = $this->starredAmong($user, $messages->pluck('id')->all());

        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $page * $perPage < $total,
            'messages' => $messages->map(fn (Message $message) => [
                ...$this->chat->presentMessage($message, $starred),
                // Which thread it came from, so the list can say so and open
                // it — a starred line with no context is just a fragment.
                'conversation' => [
                    'id' => $message->conversation->uuid,
                    'other' => $this->chat->otherPersonSummary($user, $message->conversation),
                ],
            ])->all(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Pinning
    |--------------------------------------------------------------------------
    */

    /**
     * Pin a message, or clear the pin when given null.
     *
     * Shared by everyone in the thread, unlike a star. Both people see the
     * same banner and either can change it, which is the point — a pin is for
     * the address or the date the two of you keep scrolling back for.
     */
    public function pin(User $user, Conversation $conversation, ?Message $message): Conversation
    {
        if ($message !== null) {
            abort_unless(
                $message->conversation_id === $conversation->id,
                422,
                'That message is not in this conversation.',
            );
        }

        $conversation->forceFill([
            'pinned_message_id' => $message?->id,
            'pinned_by_id' => $message === null ? null : $user->id,
            'pinned_at' => $message === null ? null : now(),
        ])->save();

        return $conversation->fresh(['participants.user', 'pinnedMessage.sender:id,uuid']);
    }

    /*
    |--------------------------------------------------------------------------
    | Forwarding
    |--------------------------------------------------------------------------
    */

    /**
     * Send a copy of a message into other conversations.
     *
     * A new message in each, not a reference to the original: the recipient
     * must never gain access to the thread it came from, and a shared row
     * would mean deleting the original deletes it everywhere it was ever
     * forwarded.
     *
     * @param  array<int, string>  $conversationUuids
     * @return array<int, Message>
     */
    public function forward(User $user, Message $source, array $conversationUuids): array
    {
        // The sender must be able to see what they are forwarding. Without
        // this, a message id from anywhere would do.
        $this->chat->findConversation($user, $source->conversation->uuid);

        abort_if($source->trashed(), 422, 'That message was deleted.');

        $sent = [];

        foreach (array_slice(array_unique($conversationUuids), 0, 10) as $uuid) {
            $target = $this->chat->findConversation($user, $uuid);

            $sent[] = $this->copyInto($user, $source, $target);
        }

        return $sent;
    }

    private function copyInto(User $user, Message $source, Conversation $target): Message
    {
        return DB::transaction(function () use ($user, $source, $target): Message {
            $locked = Conversation::whereKey($target->id)->lockForUpdate()->firstOrFail();

            $seq = $locked->last_seq + 1;

            $message = new Message([
                'type' => $source->type,
                'body' => $source->body,
                // A forward is a new message with its own identity, so it
                // gets a fresh client id rather than inheriting one — the
                // original's id belongs to the original.
                'client_uuid' => (string) \Illuminate\Support\Str::uuid7(),
            ]);

            $message->conversation_id = $target->id;
            $message->sender_id = $user->id;
            $message->seq = $seq;
            $message->forwarded = true;
            $message->save();

            /*
             | Attachments are copied as a row pointing at the same file.
             |
             | Not a second upload: the bytes are identical and duplicating
             | them would multiply storage for no gain. Safe because nothing
             | deletes an attachment's file except the orphan pruner, which
             | only ever touches rows that were never attached to a message.
             */
            if ($source->attachment !== null) {
                $copy = $source->attachment->replicate(['message_id']);

                $copy->uuid = (string) \Illuminate\Support\Str::uuid7();
                $copy->message_id = $message->id;
                $copy->user_id = $user->id;
                $copy->save();
            }

            $locked->forceFill([
                'last_seq' => $seq,
                'last_message_id' => $message->id,
                'last_message_at' => $message->created_at,
            ])->save();

            $this->chat->markSenderCaughtUp($target, $user, $seq);

            return $message;
        });
    }
}
