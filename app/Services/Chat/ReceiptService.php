<?php

namespace App\Services\Chat;

use App\Events\Chat\ReceiptsUpdated;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Delivery state: the single tick, the double tick and the blue one.
 *
 * Nothing here is stored per message. Each participant carries two sequence
 * numbers — the highest they have been handed, and the highest they have
 * read — and every tick in the thread is derived from them on the client.
 *
 * That is not only cheaper than a receipt row per message per reader, it is
 * also what produces the behaviour people recognise: opening a thread with
 * sixty unread messages moves one number, and the sender's entire column of
 * ticks turns blue at once instead of rippling upward one bubble at a time.
 */
class ReceiptService
{
    public function __construct(private readonly ChatService $chat)
    {
    }

    /**
     * "I have seen everything up to here."
     *
     * @return array<string, mixed>
     */
    public function markRead(User $me, Conversation $conversation, string $messageUuid): array
    {
        return $this->advance($me, $conversation, $messageUuid, read: true);
    }

    /**
     * "This reached my device."
     *
     * Best effort by definition — a device that is off cannot report anything
     * — so it gates nothing. No send waits on it and no screen blocks on it.
     *
     * @return array<string, mixed>
     */
    public function markDelivered(User $me, Conversation $conversation, string $messageUuid): array
    {
        return $this->advance($me, $conversation, $messageUuid, read: false);
    }

    /**
     * Move a watermark forward, never backwards.
     *
     * Being idempotent and monotonic is what makes this safe to fire on every
     * scroll tick and every socket frame without ordering guarantees: a late
     * receipt for an older message is a no-op rather than a regression that
     * would turn read ticks grey again.
     *
     * @return array<string, mixed>
     */
    private function advance(
        User $me,
        Conversation $conversation,
        string $messageUuid,
        bool $read,
    ): array {
        $participant = $this->chat->participantOrFail($conversation, $me);

        $message = Message::withTrashed()
            ->where('conversation_id', $conversation->id)
            ->where('uuid', $messageUuid)
            ->first();

        abort_if($message === null, 404, 'That message is not in this conversation.');

        $column = $read ? 'last_read_seq' : 'last_delivered_seq';

        if ($participant->{$column} >= $message->seq) {
            return $this->payload($conversation, $participant);
        }

        DB::transaction(function () use ($participant, $column, $message, $read, $conversation, $me) {
            $changes = [$column => $message->seq];

            // Delivery is implied by reading. Without this, a message read
            // straight off the socket would show a blue tick while its
            // delivered watermark still sat behind it, and any later
            // recalculation could disagree with itself.
            if ($read && $participant->last_delivered_seq < $message->seq) {
                $changes['last_delivered_seq'] = $message->seq;
            }

            if ($read) {
                // Recomputed rather than decremented. An exact count costs one
                // indexed query and is self-healing: a missed increment
                // anywhere in the system corrects itself the next time the
                // thread is opened, instead of leaving a badge that is wrong
                // forever with no way to notice.
                $changes['unread_count'] = Message::where('conversation_id', $conversation->id)
                    ->where('sender_id', '!=', $me->id)
                    ->where('seq', '>', $message->seq)
                    ->count();
            }

            $participant->forceFill($changes)->save();
        });

        $participant->refresh();

        $this->announce($conversation, $me, $participant);

        return $this->payload($conversation, $participant);
    }

    /**
     * Tell the other person the ticks moved.
     *
     * Dispatched after the transaction, like every other broadcast here — an
     * event that arrives before its own write is visible is a bug that only
     * shows up under load.
     *
     * Honours `show_read_receipts`. Somebody who has turned it off has their
     * read watermark reported as their delivered one, so the sender sees two
     * grey ticks and never a blue pair. Suppressing the event entirely would
     * be worse: the delivered tick would stop working too, and the setting
     * only promises to hide *reading*.
     */
    private function announce(
        Conversation $conversation,
        User $reader,
        ConversationParticipant $participant,
    ): void {
        $delivered = $participant->last_delivered_seq;

        $read = $reader->show_read_receipts
            ? $participant->last_read_seq
            : $delivered;

        ReceiptsUpdated::dispatch($conversation, $reader, $read, $delivered);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Conversation $conversation, ConversationParticipant $participant): array
    {
        return [
            'conversation_id' => $conversation->uuid,
            'user_id' => $participant->user->uuid,
            'last_read_seq' => $participant->last_read_seq,
            'last_delivered_seq' => $participant->last_delivered_seq,
            'unread_count' => $participant->unread_count,
        ];
    }
}
