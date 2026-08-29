#!/usr/bin/env python3
"""Phase 2 (API) — enable broadcasting and announce new messages.

Run from the famzone-api repo root, after `composer require laravel/reverb`
and `php artisan reverb:install`. Idempotent.
"""

import io
import sys

changed = []


def read(p):
    return io.open(p, encoding='utf-8').read()


def write(p, s):
    io.open(p, 'w', encoding='utf-8', newline='').write(s)


# ==================================================== bootstrap/app.php

P = 'bootstrap/app.php'
s = read(P)

if 'withBroadcasting' in s:
    print(f'{P}: already patched')
else:
    OLD = """        health: '/up',
    )
    ->withMiddleware("""

    NEW = """        health: '/up',
    )
    /*
     | Broadcasting authorisation.
     |
     | Published under the API prefix and behind auth:sanctum, which is the
     | whole trick for a mobile client. Left at its default the endpoint sits
     | in the `web` group and expects a session cookie, so a Flutter app
     | holding a bearer token gets a 403 — or worse, a redirect to a login
     | route that does not exist.
     |
     | Confirm the resolved path after changing this:
     |
     |   php artisan route:list --path=broadcasting
     */
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware("""

    if OLD not in s:
        sys.exit(f'{P}: routing anchor not found')

    write(P, s.replace(OLD, NEW, 1))
    changed.append(P)
    print(f'{P}: broadcasting enabled on api/v1 with auth:sanctum')


# ====================================================== ChatService

P = 'app/Services/Chat/ChatService.php'
s = read(P)

if 'MessageSent' in s:
    print(f'{P}: already patched')
    print('\ndone: ' + (', '.join(changed) if changed else 'nothing to do'))
    sys.exit(0)

# ---- imports ----------------------------------------------------------

OLD_USE = "use App\\Models\\Block;"
NEW_USE = (
    "use App\\Events\\Chat\\InboxUpdated;\n"
    "use App\\Events\\Chat\\MessageSent;\n"
    "use App\\Models\\Block;"
)

if OLD_USE not in s:
    sys.exit(f'{P}: import anchor not found')

s = s.replace(OLD_USE, NEW_USE, 1)

# ---- dispatch after the transaction -----------------------------------

OLD_SEND = """        return ['message' => $this->presentMessage($message), 'replayed' => false];
    }"""

NEW_SEND = """        $this->announce($conversation, $message);

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
    private function announce(Conversation $conversation, Message $message): void
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
    }"""

if OLD_SEND not in s:
    sys.exit(f'{P}: send() return not found')

s = s.replace(OLD_SEND, NEW_SEND, 1)

write(P, s)
changed.append(P)
print(f'{P}: broadcasts new messages after commit')

print('\ndone: ' + ', '.join(changed))
