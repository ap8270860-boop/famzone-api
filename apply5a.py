#!/usr/bin/env python3
"""Phase 5a — close conversations on block, and report the blocked state.

Run from the famzone-api repo root. Idempotent.
"""

import io
import sys

changed = []


def read(p):
    return io.open(p, encoding='utf-8').read()


def write(p, s):
    io.open(p, 'w', encoding='utf-8', newline='').write(s)


# =========================================================== BlockService

P = 'app/Services/Social/BlockService.php'
s = read(P)

if 'ConversationClosed' in s:
    print(f'{P}: already patched')
else:
    OLD_USE = "use App\\Models\\Block;"
    NEW_USE = (
        "use App\\Events\\Chat\\ConversationClosed;\n"
        "use App\\Models\\Block;\n"
        "use App\\Models\\Conversation;"
    )

    if OLD_USE not in s:
        sys.exit(f'{P}: import anchor not found')

    s = s.replace(OLD_USE, NEW_USE, 1)

    OLD = """        return DB::transaction(function () use ($actor, $target, $reason): Block {"""

    NEW = """        $block = DB::transaction(function () use ($actor, $target, $reason): Block {"""

    if OLD not in s:
        sys.exit(f'{P}: block() transaction not found')

    s = s.replace(OLD, NEW, 1)

    OLD_END = """            $block->save();

            return $block;
        });
    }"""

    NEW_END = """            $block->save();

            return $block;
        });

        $this->closeConversation($actor, $target);

        return $block;
    }

    /**
     * Tell both clients to let go of the thread.
     *
     * Channel authorisation runs once, on subscribe. Somebody already
     * listening when the block lands would keep receiving messages on that
     * channel forever, because nothing asks the server a second time. This is
     * what closes that window.
     *
     * Both sides, not just the blocked one: the person who blocked has the
     * thread filtered out of their inbox on the next load, but if they happen
     * to have it open right now, nothing else would tell them.
     *
     * Nothing is deleted. Blocking ends a relationship; it is not a retraction
     * of things that were already said, and quietly destroying somebody's copy
     * of a conversation is a surprising, unrecoverable side effect of a button
     * labelled "Block".
     */
    private function closeConversation(User $actor, User $target): void
    {
        $conversation = Conversation::where(
            'pair_key',
            Conversation::pairKey($actor->id, $target->id),
        )->first();

        if ($conversation === null) {
            return;
        }

        ConversationClosed::dispatch($conversation, $actor);
        ConversationClosed::dispatch($conversation, $target);
    }"""

    if OLD_END not in s:
        sys.exit(f'{P}: block() return not found')

    s = s.replace(OLD_END, NEW_END, 1)

    write(P, s)
    changed.append(P)
    print(f'{P}: closes the conversation channel on block')


# ============================================================ ChatService

P = 'app/Services/Chat/ChatService.php'
s = read(P)

if "'blocked' =>" in s:
    print(f'{P}: already patched')
else:
    # --- the per-request wall cache ------------------------------------
    OLD_CACHE = """    public function __construct(
        private readonly RelationshipService $relationships,
        private readonly PresenceService $presence,
    ) {
    }"""

    NEW_CACHE = """    public function __construct(
        private readonly RelationshipService $relationships,
        private readonly PresenceService $presence,
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
    }"""

    if OLD_CACHE not in s:
        sys.exit(f'{P}: constructor not found')

    s = s.replace(OLD_CACHE, NEW_CACHE, 1)

    # --- expose it on the payload --------------------------------------
    OLD = """            'last_message' => $conversation->lastMessage
                ? $this->presentMessage($conversation->lastMessage)
                : null,"""

    NEW = """            'last_message' => $conversation->lastMessage
                ? $this->presentMessage($conversation->lastMessage)
                : null,

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
                && isset($this->wall($me)[$other->user_id]),"""

    if OLD not in s:
        sys.exit(f'{P}: last_message payload not found')

    write(P, s.replace(OLD, NEW, 1))
    changed.append(P)
    print(f'{P}: conversations report whether they are blocked')


print('\ndone: ' + (', '.join(changed) if changed else 'nothing to do'))
