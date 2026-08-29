#!/usr/bin/env python3
"""Phase 3 — receipts over the socket, and honour the read-receipts setting.

Run from the famzone-api repo root. Idempotent.
"""

import io
import sys

changed = []


def read(p):
    return io.open(p, encoding='utf-8').read()


def write(p, s):
    io.open(p, 'w', encoding='utf-8', newline='').write(s)


# ========================================================= ReceiptService

P = 'app/Services/Chat/ReceiptService.php'
s = read(P)

if 'ReceiptsUpdated' in s:
    print(f'{P}: already patched')
else:
    OLD_USE = "use App\\Models\\Conversation;"
    NEW_USE = "use App\\Events\\Chat\\ReceiptsUpdated;\nuse App\\Models\\Conversation;"

    if OLD_USE not in s:
        sys.exit(f'{P}: import anchor not found')

    s = s.replace(OLD_USE, NEW_USE, 1)

    OLD_TAIL = """            $participant->forceFill($changes)->save();
        });

        return $this->payload($conversation, $participant->refresh());
    }"""

    NEW_TAIL = """            $participant->forceFill($changes)->save();
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
    }"""

    if OLD_TAIL not in s:
        sys.exit(f'{P}: advance() tail not found')

    s = s.replace(OLD_TAIL, NEW_TAIL, 1)

    write(P, s)
    changed.append(P)
    print(f'{P}: broadcasts receipt changes')


# ============================================================ ChatService

P = 'app/Services/Chat/ChatService.php'
s = read(P)

if 'show_read_receipts' in s:
    print(f'{P}: already patched')
else:
    OLD = """                [
                    'state' => $other->state,
                    'last_read_seq' => (int) $other->last_read_seq,
                    'last_delivered_seq' => (int) $other->last_delivered_seq,
                ],"""

    NEW = """                [
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
                ],"""

    if OLD not in s:
        sys.exit(f'{P}: presentConversation watermarks not found')

    write(P, s.replace(OLD, NEW, 1))
    changed.append(P)
    print(f'{P}: read receipts honour the privacy setting')


# ================================================================== User

P = 'app/Models/User.php'
s = read(P)

if 'getAuthIdentifierForBroadcasting' in s:
    print(f'{P}: already patched')
else:
    OLD = "    public function isActive(): bool"

    NEW = """    /**
     * The id other people see for you on a presence channel.
     *
     * Laravel puts this in the presence payload every other member of the
     * channel receives, and it defaults to the primary key — which would put
     * the internal auto-increment id straight in front of other users. The
     * uuid is the only identifier allowed to leave the server, so it is the
     * one that goes here.
     *
     * Easy to miss: nothing in the app reads it, and it only becomes visible
     * once somebody inspects a websocket frame.
     */
    public function getAuthIdentifierForBroadcasting(): string
    {
        return $this->uuid;
    }

    public function isActive(): bool"""

    if OLD not in s:
        sys.exit(f'{P}: isActive() anchor not found')

    write(P, s.replace(OLD, NEW, 1))
    changed.append(P)
    print(f'{P}: presence identifier is the uuid, not the primary key')


print('\ndone: ' + (', '.join(changed) if changed else 'nothing to do'))
