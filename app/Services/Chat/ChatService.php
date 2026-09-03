<?php

namespace App\Services\Chat;

use App\Events\Chat\InboxUpdated;
use App\Events\Chat\MessageSent;
use App\Models\Block;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Follow;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageHide;
use App\Models\User;
use App\Services\Social\RelationshipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conversations and messages.
 *
 * The rule this whole service is built around: messages travel over HTTP and
 * the websocket only announces that one arrived. Sending is a transaction
 * that either commits or does not, is safe to retry, and works with the
 * socket down. Nothing is ever only in the socket, because a socket that
 * drops in a lift would take the message with it.
 *
 * Broadcasting is deliberately absent here. It arrives in the next phase as
 * events dispatched after this service commits — never inside its
 * transactions, which would let a recipient learn about a row that is not yet
 * visible to them.
 */
class ChatService
{
    /** Scrollback page size. */
    public const PER_PAGE = 40;

    /**
     * Hidden-message answers already fetched this request, keyed
     * `userId:messageId`. A hide is permanent, so nothing here can go
     * stale within the life of one request.
     *
     * @var array<string, bool>
     */
    private array $hidden = [];

    /** Inbox page size. */
    public const INBOX_PER_PAGE = 25;

    /**
     * How many messages someone may send into a thread the other person has
     * not accepted yet.
     *
     * A message request is a way to reach a stranger once, politely. Without
     * a cap it is a way to shout at them, and no amount of moderation after
     * the fact undoes that.
     */
    public const REQUEST_CAP = 3;

    public function __construct(
        private readonly RelationshipService $relationships,
        private readonly PresenceService $presence,
        private readonly AttachmentService $attachments,
        private readonly ReactionService $reactions,
    ) {
    }

    /**
     * Everybody the current caller cannot interact with, fetched once.
     *
     * An inbox page presents 25 conversations and each one needs to know
     * whether a wall stands between the two people. Asking per row is 25
     * queries for a fact that does not change inside one request; asking once
     * is one query and an array lookup.
     *
     * Keyed by user id because a request only ever presents for one viewer,
     * but keying it means a queued job presenting for several cannot poison
     * the answer for the others.
     *
     * @var array<int, array<int, true>>
     */
    private array $walls = [];

    /**
     * @return array<int, true>
     */
    private function wall(User $viewer): array
    {
        if (isset($this->walls[$viewer->id])) {
            return $this->walls[$viewer->id];
        }

        $ids = [];

        // Both columns in one pass. Block::wallIds() exists for composing into
        // a whereNotIn and returns an unaliased CASE expression, which is
        // exactly wrong for reading values out.
        Block::query()
            ->where('blocker_id', $viewer->id)
            ->orWhere('blocked_id', $viewer->id)
            ->get(['blocker_id', 'blocked_id'])
            ->each(function (Block $block) use ($viewer, &$ids) {
                $ids[$block->blocker_id === $viewer->id
                    ? $block->blocked_id
                    : $block->blocker_id] = true;
            });

        return $this->walls[$viewer->id] = $ids;
    }

    /*
    |--------------------------------------------------------------------------
    | Permission
    |--------------------------------------------------------------------------
    */

    /**
     * Whether two people are allowed to exchange messages at all.
     *
     * Blocking is the only hard wall, and it applies in both directions: the
     * person who blocked should not receive messages, and the person who was
     * blocked should not learn that they were by watching sends succeed.
     *
     * Account privacy deliberately does not appear here. is_private governs
     * who may see your posts; whether a message lands in the inbox or in
     * Requests is governed by the follow relation, in stateFor() below.
     * Conflating the two leaves a public account with no defence against
     * unwanted messages and a private account unreachable by anyone it has
     * not already approved.
     */
    public function canMessage(User $me, User $other): bool
    {
        if ($me->id === $other->id) {
            return false;
        }

        return ! Block::between($me->id, $other->id)->exists();
    }

    /**
     * The state a new participant row starts in.
     *
     * Accepted when the recipient already follows the sender — they asked to
     * hear from this person. A request otherwise.
     */
    private function stateFor(User $recipient, User $sender): string
    {
        $follows = Follow::between($recipient->id, $sender->id)->accepted()->exists();

        return $follows
            ? ConversationParticipant::STATE_ACCEPTED
            : ConversationParticipant::STATE_PENDING;
    }

    /*
    |--------------------------------------------------------------------------
    | Conversations
    |--------------------------------------------------------------------------
    */

    /**
     * The one-to-one thread between two people, creating it if needed.
     *
     * Idempotent by construction. The pair key is derived from both ids, so
     * both people compute the same value and the unique index does the
     * arbitration — two devices tapping Message at the same instant end up in
     * the same thread rather than two half-empty ones.
     */
    public function findOrCreateDirect(User $me, User $other): Conversation
    {
        abort_unless($this->canMessage($me, $other), 403, 'You cannot message this account.');

        $key = Conversation::pairKey($me->id, $other->id);

        if ($existing = Conversation::where('pair_key', $key)->first()) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($me, $other, $key) {
                $conversation = new Conversation([
                    'type' => Conversation::TYPE_DIRECT,
                    'pair_key' => $key,
                ]);

                $conversation->save();

                // The opener has accepted by definition — they started it.
                $this->addParticipant(
                    $conversation,
                    $me,
                    ConversationParticipant::STATE_ACCEPTED,
                );

                $this->addParticipant(
                    $conversation,
                    $other,
                    $this->stateFor($other, $me),
                );

                return $conversation;
            });
        } catch (QueryException $e) {
            if (! $this->isDuplicate($e)) {
                throw $e;
            }

            // The other device won the race by microseconds. Their row is the
            // one that exists, so use it.
            return Conversation::where('pair_key', $key)->firstOrFail();
        }
    }

    /**
     * Put one person in a conversation.
     *
     * The ids are assigned as properties rather than passed to the
     * constructor, because they are deliberately not in the model's Fillable
     * list. Which user a participant row belongs to is exactly the kind of
     * column that must never be settable from request data — the guard is
     * doing its job, so the service works with it rather than widening it.
     *
     * Mass assignment fails silently: createMany() dropped user_id without a
     * word and left MySQL to complain about a column with no default. Worth
     * remembering the next time a model refuses to save something obvious.
     */
    private function addParticipant(
        Conversation $conversation,
        User $user,
        string $state,
    ): ConversationParticipant {
        $participant = new ConversationParticipant(['state' => $state]);

        $participant->conversation_id = $conversation->id;
        $participant->user_id = $user->id;
        $participant->save();

        return $participant;
    }

    /**
     * Someone's inbox.
     *
     * @return array<string, mixed>
     */
    public function inbox(
        User $me,
        string $state = ConversationParticipant::STATE_ACCEPTED,
        int $page = 1,
        int $perPage = self::INBOX_PER_PAGE,
        bool $archived = false,
    ): array {
        $perPage = max(1, min(50, $perPage));

        $query = Conversation::query()
            ->whereIn('id', ConversationParticipant::query()
                ->select('conversation_id')
                ->where('user_id', $me->id)
                ->where('state', $state)
                ->whereNull('left_at')
                /*
                 | Archived threads are not gone, they are elsewhere.
                 |
                 | The same endpoint serves both lists rather than a second
                 | one that would duplicate the blocking rule, the
                 | never-written-in rule, the eager loads and the ordering —
                 | four things that must not be allowed to drift apart.
                 */
                ->when(
                    $archived,
                    fn (Builder $q) => $q->whereNotNull('archived_at'),
                    fn (Builder $q) => $q->whereNull('archived_at'),
                ))
            // A thread opened by tapping Message but never written in is not
            // yet a conversation. It exists so the composer has somewhere to
            // put a draft; it has no business in either person's list until
            // something is actually said.
            ->whereNotNull('last_message_at')
            /*
             | Blocking hides a direct thread without destroying it.
             |
             | Only a direct thread. In a group, a wall between two members is
             | a fact about those two — hiding the whole room from everybody
             | else in it because of one pair would be a strange kind of
             | moderation, and would make a group vanish for reasons nobody
             | could see.
             */
            ->where(fn (Builder $outer) => $outer
                ->where('type', '!=', Conversation::TYPE_DIRECT)
                ->orWhereDoesntHave('participants', fn (Builder $q) => $q
                    ->where('user_id', '!=', $me->id)
                    ->whereIn('user_id', Block::wallIds($me->id))))
            /*
             | Pinned chats first.
             |
             | Ordered by a correlated subquery against this person's own
             | participant row, because a pin belongs to one reader: it
             | cannot be a column on the conversation, and a plain join would
             | bring the other participant's row along with it.
             |
             | MySQL sorts NULLs last on a descending order, so every
             | unpinned thread falls in behind every pinned one for free.
             */
            ->orderByDesc(ConversationParticipant::query()
                ->select('pinned_at')
                ->whereColumn('conversation_id', 'conversations.id')
                ->where('user_id', $me->id)
                ->limit(1))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        $total = (clone $query)->count();

        $conversations = $query
            ->with([
                'participants.user',
                'lastMessage.sender:id,uuid',
                'lastMessage.attachment',
                'pinnedMessage.sender:id,uuid',
            ])
            ->forPage($page, $perPage)
            ->get();

        // One query for the whole page: which of these last messages the
        // viewer has deleted for themselves. Without it every row would ask
        // on its own, and twenty rows would be twenty queries for a boolean.
        $this->warmHidden($me, $conversations->pluck('last_message_id')->all());

        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $page * $perPage < $total,
            'conversations' => $conversations
                ->map(fn (Conversation $c) => $this->presentConversation($me, $c))
                ->all(),
        ];
    }

    /**
     * Unread totals for the app badge.
     *
     * Two numbers, not one. Requests are counted separately because they are
     * a different kind of attention: an unread message from a friend is a
     * conversation waiting, a request is a decision waiting.
     *
     * @return array<string, int>
     */
    public function unreadSummary(User $me): array
    {
        $rows = ConversationParticipant::query()
            ->where('user_id', $me->id)
            ->whereNull('left_at')
            /*
             | Archived threads are deliberately outside the badge.
             |
             | Putting a chat away is a statement that it should stop asking
             | for attention; a number on the home screen it still feeds
             | would make the gesture pointless.
             */
            ->whereNull('archived_at')
            /*
             | A thread somebody marked unread counts as one.
             |
             | Otherwise the row shows a dot the app badge does not
             | know about, and the two disagree on the home screen.
             */
            ->selectRaw(
                'state, SUM(CASE WHEN unread_count > 0 THEN unread_count'
                .' WHEN marked_unread = 1 THEN 1 ELSE 0 END) as unread,'
                .' COUNT(*) as threads'
            )
            ->groupBy('state')
            ->get()
            ->keyBy('state');

        // get(), not [] — array access on a Collection warns for a missing
        // key before the null-coalesce ever sees it, and a person with no
        // pending requests is the normal case, not an edge one.
        $accepted = $rows->get(ConversationParticipant::STATE_ACCEPTED);
        $pending = $rows->get(ConversationParticipant::STATE_PENDING);

        // One extra count, for the Archived row at the top of the inbox.
        // It shows how many are in there, not how many are unread — see
        // above for why archived threads do not carry an unread number.
        $archived = ConversationParticipant::query()
            ->where('user_id', $me->id)
            ->whereNull('left_at')
            ->where('state', ConversationParticipant::STATE_ACCEPTED)
            ->whereNotNull('archived_at')
            ->count();

        return [
            'unread' => (int) ($accepted->unread ?? 0),
            'threads' => (int) ($accepted->threads ?? 0),
            'requests' => (int) ($pending->threads ?? 0),
            'archived' => $archived,
        ];
    }

    /**
     * Accept a message request.
     *
     * @return array<string, mixed>
     */
    public function accept(User $me, Conversation $conversation): array
    {
        $participant = $this->participantOrFail($conversation, $me);

        $participant->forceFill([
            'state' => ConversationParticipant::STATE_ACCEPTED,
            'left_at' => null,
        ])->save();

        return $this->presentConversation($me, $conversation->fresh(['participants.user', 'lastMessage.sender']));
    }

    /**
     * Leave a thread, or decline a request.
     *
     * The row survives with left_at set rather than being deleted, so a later
     * message drops the person back into the same thread instead of starting
     * a second one beside it.
     */
    public function leave(User $me, Conversation $conversation): void
    {
        $this->participantOrFail($conversation, $me)
            ->forceFill(['left_at' => now(), 'unread_count' => 0])
            ->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Scrollback.
     *
     * Cursors are sequence numbers, which are per-conversation and start at 1
     * — they order the thread without revealing anything about the database
     * behind it, so they can be handed to the client as-is.
     *
     * `before` walks backwards through history; `after` fills the gap left by
     * a dropped websocket. Both return messages in ascending order, because a
     * list that changes direction depending on which parameter was used is a
     * bug waiting to be written.
     *
     * @return array<string, mixed>
     */
    public function history(
        User $me,
        Conversation $conversation,
        ?int $before = null,
        ?int $after = null,
        int $limit = self::PER_PAGE,
    ): array {
        $this->participantOrFail($conversation, $me);

        $limit = max(1, min(100, $limit));

        $query = $conversation->messages()
            ->withTrashed()
            /*
             | Deleted for me.
             |
             | Filtered in the query, not after it: a page is forty rows, and
             | dropping hidden ones afterwards would hand back short pages
             | that get shorter the more somebody has deleted.
             */
            ->whereNotIn('id', MessageHide::idsFor($me->id))
            ->with([
                'sender:id,uuid',
                'attachment',
                'replyTo.sender:id,uuid',
                'reactions.user:id,uuid',
            ]);

        if ($after !== null) {
            $messages = (clone $query)->where('seq', '>', $after)
                ->orderBy('seq')
                ->limit($limit)
                ->get();
        } else {
            // Newest-first for the fetch so the limit takes the most recent
            // page, then reversed for the caller.
            $messages = (clone $query)
                ->when($before !== null, fn (Builder $q) => $q->where('seq', '<', $before))
                ->orderByDesc('seq')
                ->limit($limit)
                ->get()
                ->reverse()
                ->values();
        }

        $oldest = $messages->first()?->seq;

        return [
            'messages' => $messages->map(fn (Message $m) => $this->presentMessage($m))->all(),
            /*
             | Whether anything older than this page exists, so the client
             | knows when to stop asking.
             |
             | Only meaningful on the scroll-up path. A gap fetch walks
             | forwards from a cursor the client already holds, so by
             | definition it is not missing anything older and answering
             | otherwise would send it hunting for history it already has.
             */
            'has_more' => $after === null && $oldest !== null && $oldest > 1,
            'oldest_seq' => $oldest,
            'newest_seq' => $messages->last()?->seq,
        ];
    }

    /**
     * Send a message.
     *
     * Idempotent on client_uuid. A device that times out and retries gets the
     * original message back rather than creating a second one, and the reply
     * is a success rather than a conflict — from the sender's point of view
     * the message was sent, which is true.
     *
     * @param  array{client_uuid: string, type: string, body: ?string}  $payload
     * @return array<string, mixed>
     */
    public function send(User $sender, Conversation $conversation, array $payload): array
    {
        $mine = $this->participantOrFail($conversation, $sender);

        /*
         | Null in a group, deliberately.
         |
         | otherParticipant() answers "the other one", which in a room of six
         | is an arbitrary person rather than a wrong answer you would notice.
         | Everything below that depends on there being exactly one other
         | person is therefore skipped for groups: the block rule is about a
         | pair, and the message-request cap is about somebody you have not
         | been introduced to.
         */
        $other = $conversation->isDirect()
            ? $this->otherParticipant($conversation, $sender)
            : null;

        if ($conversation->isDirect()) {
            abort_if($other === null, 422, 'That conversation has nobody in it.');

            abort_unless(
                $this->canMessage($sender, $other->user),
                403,
                'You cannot message this account.',
            );
        }

        // Cheap path first: an obvious replay never opens a transaction.
        if ($existing = $this->findByClientUuid($conversation, $payload['client_uuid'])) {
            return ['message' => $this->presentMessage($existing), 'replayed' => true];
        }

        if ($other !== null) {
            $this->guardRequestCap($conversation, $sender, $other);
        }

        try {
            $message = DB::transaction(
                fn () => $this->persist($sender, $conversation, $other, $payload),
            );
        } catch (QueryException $e) {
            if (! $this->isDuplicate($e)) {
                throw $e;
            }

            // Two retries of the same send crossed in flight.
            $message = $this->findByClientUuid($conversation, $payload['client_uuid']);

            abort_if($message === null, 409, 'That message could not be saved.');

            return ['message' => $this->presentMessage($message), 'replayed' => true];
        }

        $this->announce($conversation, $message);

        return ['message' => $this->presentMessage($message), 'replayed' => false];
    }

    /**
     * Tell everyone who should know.
     *
     * Called after DB::transaction() has returned, which is to say after the
     * commit. Dispatching from inside the transaction is the classic way to
     * break this: the event reaches the recipient, the recipient fetches the
     * message, and the row is not visible yet. It only shows up under load,
     * which is the worst time to find it.
     *
     * Two events, two jobs. The conversation channel repaints an open chat
     * screen; each recipient's own channel keeps their inbox ordered and
     * their badge correct while no chat screen is open. Publishing only one
     * of them leaves the other wrong.
     *
     * Replays are deliberately silent. A retry of a message that already
     * arrived must not make it arrive twice.
     */
    public function announce(Conversation $conversation, Message $message): void
    {
        MessageSent::dispatch($message);

        // Re-queried rather than read off the loaded relation. persist() can
        // pull somebody back into a thread they had left, and the in-memory
        // copy still says they are gone — which would silently drop the one
        // notification that matters most.
        $participants = $conversation->participants()
            ->with('user')
            ->whereNull('left_at')
            ->get();

        foreach ($participants as $participant) {
            InboxUpdated::dispatch($conversation, $participant->user);
        }
    }

    /**
     * The write itself, inside a transaction.
     */
    private function persist(
        User $sender,
        Conversation $conversation,
        ?ConversationParticipant $other,
        array $payload,
    ): Message {
        /*
         | Lock the conversation row before reading the counter.
         |
         | The sequence number has to be a genuine total order: without the
         | lock, two people sending at the same instant both read the same
         | last_seq, both write it back, and one message either overwrites the
         | other or trips the unique index. The lock is held for the length of
         | one insert and contended by at most two people, so the cost is
         | nothing and the guarantee is absolute.
         */
        $locked = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();

        $seq = $locked->last_seq + 1;

        $message = new Message([
            'type' => $payload['type'],
            'body' => $payload['body'],
            'client_uuid' => $payload['client_uuid'],
        ]);

        $message->conversation_id = $conversation->id;
        $message->sender_id = $sender->id;
        $message->seq = $seq;

        /*
         | Resolve the message being replied to, scoped to this conversation.
         |
         | The scope is the security check, not a convenience: without it a
         | crafted reply_to_id would quote a line out of a thread the sender
         | has no access to, and the quote is rendered verbatim on both
         | screens.
         |
         | withTrashed, because replying to something that was then deleted is
         | ordinary and the reply should still stand.
         */
        if (filled($payload['reply_to_id'] ?? null)) {
            $parent = Message::withTrashed()
                ->where('conversation_id', $conversation->id)
                ->where('uuid', $payload['reply_to_id'])
                ->first();

            abort_if($parent === null, 422, 'That message is not in this conversation.');

            $message->reply_to_id = $parent->id;
        }

        $message->save();

        /*
         | Adopt the upload, inside the same transaction as the message.
         |
         | If this throws — the upload belongs to somebody else, or has
         | already been sent — the message is rolled back with it. A message
         | that exists but points at nothing would render as a permanently
         | broken attachment on the recipient's screen, with no way back.
         */
        if ($message->hasMedia() && filled($payload['upload_id'] ?? null)) {
            $this->attachments->attach($sender, $message, $payload['upload_id']);

            $message->setRelation(
                'attachment',
                MessageAttachment::where('message_id', $message->id)->first(),
            );
        }

        $locked->forceFill([
            'last_seq' => $seq,
            'last_message_id' => $message->id,
            'last_message_at' => $message->created_at,
        ])->save();

        // You have read what you just wrote, on this device and every other.
        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $sender->id)
            ->update([
                'last_read_seq' => $seq,
                'last_delivered_seq' => $seq,
                'unread_count' => 0,
                'updated_at' => now(),
            ]);

        // A message to somebody who declined puts the thread back in front of
        // them as a fresh request rather than disappearing into a row nobody
        // will ever look at again.
        /*
         | A message reopens a direct thread the other person had left.
         |
         | Not a group: leaving a group is a decision the room watched you
         | make, and a message from somebody else must not quietly put you
         | back in it.
         */
        $reopen = $other !== null && $other->hasLeft()
            ? ['left_at' => null, 'state' => $this->stateFor($other->user, $sender)]
            : [];

        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $sender->id)
            // In a group, only the people still in it.
            ->when($conversation->isGroup(), fn ($q) => $q->whereNull('left_at'))
            ->update(array_merge($reopen, [
                'unread_count' => DB::raw('unread_count + 1'),
                'updated_at' => now(),
            ]));

        return $message;
    }

    /**
     * Delete for everyone.
     *
     * Soft, so the recipient's client can swap the bubble for a tombstone.
     * Removing the row outright takes a line out of the middle of somebody
     * else's conversation with no explanation, which reads as a bug.
     */
    public function deleteMessage(User $me, Message $message): void
    {
        abort_unless($message->sender_id === $me->id, 403, 'You can only delete your own messages.');

        $message->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    public function presentMessage(Message $message, ?array $starred = null): array
    {
        $deleted = $message->trashed();

        return [
            'id' => $message->uuid,
            'client_id' => $message->client_uuid,
            'seq' => $message->seq,
            'sender_id' => $message->sender?->uuid,
            'type' => $deleted ? Message::TYPE_TEXT : $message->type,
            // The body of a deleted message is never sent again, not even to
            // the person who wrote it. "Deleted" has to mean deleted.
            'body' => $deleted ? null : $message->body,
            'deleted' => $deleted,
            'forwarded' => (bool) $message->forwarded,

            /*
             | Whether the viewer starred it.
             |
             | Passed in as a set for the whole page rather than
             | queried per message — a scrollback of forty would
             | otherwise be forty queries for a boolean. Null means
             | the caller has no viewer (a broadcast, say), and a
             | message nobody is looking at is starred by nobody.
             */
            'starred' => $starred !== null && isset($starred[$message->id]),

            /*
             | Dropped entirely once deleted, along with the body. "Deleted"
             | has to mean deleted — leaving a live signed link behind would
             | make the word a lie, and the file is still on disk.
             */
            'attachment' => $deleted || $message->attachment === null
                ? null
                : $this->attachments->present($message->attachment),

            'reply_to' => $this->presentQuote($message->replyTo),

            // Dropped with the body once deleted — a tombstone with
            // six laughing faces on it is nobody's idea of good taste.
            'reactions' => $deleted
                ? []
                : $this->reactions->present($message),

            'edited_at' => $message->edited_at?->toIso8601String(),
            'created_at' => $message->created_at->toIso8601String(),
        ];
    }

    /**
     * A one-line version of a quoted message.
     *
     * Deliberately not the full DTO. A quote needs enough to recognise the
     * message and nothing more, and nesting whole messages inside messages
     * would let one deep reply chain drag half a conversation onto the wire.
     *
     * @return array<string, mixed>|null
     */
    private function presentQuote(?Message $quoted): ?array
    {
        if ($quoted === null) {
            return null;
        }

        $gone = $quoted->trashed();

        return [
            'id' => $quoted->uuid,
            'sender_id' => $quoted->sender?->uuid,
            'type' => $gone ? Message::TYPE_TEXT : $quoted->type,
            // Truncated: a quote is a pointer, and a 4000-character one would
            // bury the reply underneath it.
            'body' => $gone ? null : Str::limit((string) $quoted->body, 140),
            'deleted' => $gone,
        ];
    }

    /**
     * Which of these messages the viewer has deleted for themselves.
     *
     * Public so the inbox can fill the cache for a whole page in one query
     * before it presents any row.
     *
     * @param  array<int, int|null>  $messageIds
     */
    public function warmHidden(User $me, array $messageIds): void
    {
        $ids = array_values(array_unique(array_filter($messageIds)));

        if ($ids === []) {
            return;
        }

        $hidden = MessageHide::where('user_id', $me->id)
            ->whereIn('message_id', $ids)
            ->pluck('message_id')
            ->all();

        foreach ($ids as $id) {
            $this->hidden[$me->id.':'.$id] = in_array($id, $hidden, true);
        }
    }

    private function hasHidden(User $me, int $messageId): bool
    {
        $key = $me->id.':'.$messageId;

        if (! array_key_exists($key, $this->hidden)) {
            $this->warmHidden($me, [$messageId]);
        }

        return $this->hidden[$key] ?? false;
    }

    /**
     * The newest message in this thread the viewer has not hidden.
     *
     * Almost always the conversation's own last message, with no query at
     * all. It only reaches for the database when that message is one this
     * person deleted for themselves — rare, and the alternative is an inbox
     * row previewing a message you just deleted, which is the kind of bug
     * people screenshot.
     */
    private function visibleLastMessage(User $me, Conversation $conversation): ?Message
    {
        $last = $conversation->lastMessage;

        if ($last === null || ! $this->hasHidden($me, $last->id)) {
            return $last;
        }

        return $conversation->messages()
            ->withTrashed()
            ->whereNotIn('id', MessageHide::idsFor($me->id))
            ->with(['sender:id,uuid', 'attachment'])
            ->orderByDesc('seq')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentConversation(
        User $me,
        Conversation $conversation,
        bool $withMembers = false,
    ): array {
        $mine = $conversation->participants->firstWhere('user_id', $me->id);

        // Null for a group, where there is no "other person" to be the
        // subject of the row. The group block below carries what a group row
        // needs instead — its name, its picture, and how many are in it.
        $other = $conversation->isGroup() ? null : $conversation->participants->firstWhere(
            fn (ConversationParticipant $p) => $p->user_id !== $me->id,
        );

        $last = $this->visibleLastMessage($me, $conversation);

        return [
            'id' => $conversation->uuid,
            'type' => $conversation->type,
            'state' => $mine?->state ?? ConversationParticipant::STATE_ACCEPTED,
            'unread_count' => (int) ($mine?->unread_count ?? 0),
            'muted' => (bool) $mine?->isMuted(),
            'muted_until' => $mine?->muted_until?->toIso8601String(),

            /*
             | Mine alone, both of them.
             |
             | Pinning a chat to the top of my list says nothing about
             | where it sits in theirs — unlike a pinned message,
             | which is shared and lives on the conversation.
             */
            'pinned' => $mine?->pinned_at !== null,

            // Null on a direct thread, so a client can tell the two
            // apart from one field rather than from `type`.
            'group' => $conversation->isGroup()
                ? app(GroupService::class)->present($me, $conversation, $withMembers)
                : null,
            'archived' => $mine?->archived_at !== null,
            'marked_unread' => (bool) ($mine?->marked_unread ?? false),
            // Both read off the message actually being shown, so a row
            // whose newest message is hidden reports the one it fell back to
            // rather than a timestamp for something invisible.
            'last_message_at' => ($last?->created_at ?? $conversation->last_message_at)
                ?->toIso8601String(),
            'last_message' => $last === null ? null : $this->presentMessage($last),

            /*
             | Whether a wall stands between these two, in either direction.
             |
             | The client needs this to disable the composer and say why.
             | Without it, a blocked thread looks perfectly normal until you
             | type something and get a 403 — which is a worse way to find out
             | and tells you nothing about what to do next.
             |
             | Deliberately not `blocked_by_me`: the person who was blocked
             | must not be able to tell the difference between being blocked
             | and the other account having gone quiet.
             */
            'blocked' => $other !== null
                && isset($this->wall($me)[$other->user_id]),

            /*
             | Shared by both people, unlike a star — but still not shown to
             | somebody who deleted or cleared that message on their own
             | side. A banner quoting a message you deliberately removed is
             | worse than no banner at all.
             */
            'pinned_message' => $conversation->pinnedMessage === null
                || $this->hasHidden($me, $conversation->pinnedMessage->id)
                ? null
                : $this->presentMessage($conversation->pinnedMessage),

            // My own watermarks, so a client returning after being offline
            // knows where it left off without guessing.
            'me' => [
                'last_read_seq' => (int) ($mine?->last_read_seq ?? 0),
                'last_delivered_seq' => (int) ($mine?->last_delivered_seq ?? 0),
            ],

            // Theirs, which is what the ticks are drawn from: a message is
            // read when its seq is at or below their read watermark. Two
            // integers repaint the whole column.
            'other' => $other === null ? null : array_merge(
                $this->presentPerson($me, $other->user),
                [
                    'state' => $other->state,
                    /*
                     | Read receipts are a setting, and it has to hold here as
                     | well as over the socket. Somebody who has turned them
                     | off reports their read watermark as their delivered
                     | one, so the sender sees two grey ticks and never a blue
                     | pair — including on a cold load, where the socket has
                     | said nothing yet.
                     |
                     | Leaving this to the broadcast alone would mean the
                     | setting worked until you pulled to refresh.
                     */
                    'last_read_seq' => (int) ($other->user->show_read_receipts
                        ? $other->last_read_seq
                        : $other->last_delivered_seq),
                    'last_delivered_seq' => (int) $other->last_delivered_seq,
                ],
                $this->presence->presenceFor($me, $other->user),
            ),
        ];
    }

    /**
     * The other person in a direct thread, summarised.
     *
     * Public because the Starred screen lists messages from many threads and
     * has to say which one each came from.
     *
     * @return array<string, mixed>|null
     */
    public function otherPersonSummary(User $viewer, Conversation $conversation): ?array
    {
        $other = $this->otherParticipant($conversation, $viewer);

        return $other === null ? null : $this->presentPerson($viewer, $other->user);
    }

    /**
     * Move the sender's watermarks to a message they just wrote, and give
     * everybody else one unread.
     *
     * Extracted so forwarding does the same bookkeeping as sending. A
     * forwarded message that left the sender's own unread count sitting at
     * one would put a badge on a conversation they were just looking at.
     */
    public function markSenderCaughtUp(Conversation $conversation, User $sender, int $seq): void
    {
        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $sender->id)
            ->update([
                'last_read_seq' => $seq,
                'last_delivered_seq' => $seq,
                'unread_count' => 0,
                'updated_at' => now(),
            ]);

        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $sender->id)
            ->whereNull('left_at')
            ->update([
                'unread_count' => DB::raw('unread_count + 1'),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPerson(User $viewer, User $person): array
    {
        $isFollower = Follow::between($person->id, $viewer->id)->accepted()->exists();

        return [
            'user_id' => $person->uuid,
            'name' => $person->name,
            'username' => $person->username,
            // Honours the alternate "security" avatar exactly as the profile
            // does — one rule, resolved in one place.
            'avatar_url' => $this->relationships->avatarFor($viewer, $person, $isFollower),
            'is_private' => (bool) $person->is_private,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function findConversation(User $me, string $uuid): Conversation
    {
        $conversation = Conversation::with([
            'participants.user',
            'lastMessage.sender:id,uuid',
            'pinnedMessage.sender:id,uuid',
        ])
            ->where('uuid', $uuid)
            ->first();

        // 404 rather than 403 when the caller is not in it: whether a
        // conversation exists between two other people is not their business.
        abort_if($conversation === null, 404, 'That conversation does not exist.');
        abort_unless(
            $conversation->participants->contains('user_id', $me->id),
            404,
            'That conversation does not exist.',
        );

        return $conversation;
    }

    public function participantOrFail(Conversation $conversation, User $user): ConversationParticipant
    {
        $participant = $conversation->participants->firstWhere('user_id', $user->id)
            ?? $conversation->participants()->where('user_id', $user->id)->first();

        abort_if($participant === null, 403, 'You are not in that conversation.');

        return $participant;
    }

    public function otherParticipant(Conversation $conversation, User $user): ?ConversationParticipant
    {
        return $conversation->participants
            ->firstWhere(fn (ConversationParticipant $p) => $p->user_id !== $user->id);
    }

    private function findByClientUuid(Conversation $conversation, string $clientUuid): ?Message
    {
        return Message::withTrashed()
            ->with([
                'sender:id,uuid',
                'attachment',
                'replyTo.sender:id,uuid',
                'reactions.user:id,uuid',
            ])
            ->where('conversation_id', $conversation->id)
            ->where('client_uuid', $clientUuid)
            ->first();
    }

    /**
     * Stop a stranger from filling somebody's Requests tab.
     */
    private function guardRequestCap(
        Conversation $conversation,
        User $sender,
        ConversationParticipant $other,
    ): void {
        if (! $other->isPending()) {
            return;
        }

        $sent = Message::where('conversation_id', $conversation->id)
            ->where('sender_id', $sender->id)
            ->count();

        abort_if(
            $sent >= self::REQUEST_CAP,
            403,
            'You cannot send more messages until they accept your request.',
        );
    }

    /**
     * A unique-constraint violation, as opposed to any other database error.
     */
    private function isDuplicate(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
