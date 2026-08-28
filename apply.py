#!/usr/bin/env python3
"""Phase 1 — wire the chat service into routes and V1Controller.

Run from the famzone-api repo root. Idempotent: re-running is a no-op.
"""

import io
import sys

changed = []


def read(path):
    return io.open(path, encoding='utf-8').read()


def write(path, s):
    io.open(path, 'w', encoding='utf-8', newline='').write(s)


# ================================================== RelationshipService

P = 'app/Services/Social/RelationshipService.php'
s = read(P)

if 'public function avatarFor' in s:
    print(f'{P}: already patched')
elif 'private function avatarFor' not in s:
    sys.exit(f'{P}: avatarFor not found')
else:
    # ChatService needs the same alternate-avatar rule the profile uses.
    # Widening the visibility keeps one implementation rather than copying
    # a privacy rule into a second file where the two can drift apart.
    s = s.replace('private function avatarFor', 'public function avatarFor', 1)
    write(P, s)
    changed.append(P)
    print(f'{P}: avatarFor made public')


# ============================================================== routes

P = 'routes/api.php'
s = read(P)

ROUTES_ANCHOR = """        /*
         | Profile. The username check runs on every keystroke (debounced), so
"""

CHAT_ROUTES = """        /*
         | Chat.
         |
         | Messages travel over HTTP; the websocket only announces that one
         | arrived. Every screen can rebuild itself from these endpoints
         | alone, which is what makes a dropped socket a delay rather than a
         | lost message.
         */
        Route::post('conversations', [V1Controller::class, 'startConversation'])
            ->middleware('throttle:60,1')
            ->name('conversations.store');

        Route::get('conversations', [V1Controller::class, 'conversations'])
            ->name('conversations.index');

        /*
         | Before conversations/{uuid}, so the literal segment is not
         | swallowed by the parameter — the same ordering rule that puts
         | users/search above users/{uuid}.
         */
        Route::get('conversations/unread-count', [V1Controller::class, 'chatUnreadCount'])
            ->middleware('throttle:120,1')
            ->name('conversations.unread');

        Route::prefix('conversations/{uuid}')->name('conversations.')->group(function () {
            Route::get('/', [V1Controller::class, 'showConversation'])->name('show');
            Route::delete('/', [V1Controller::class, 'leaveConversation'])->name('leave');

            Route::get('messages', [V1Controller::class, 'conversationMessages'])
                ->name('messages');

            Route::post('messages', [V1Controller::class, 'sendMessage'])
                ->middleware('throttle:60,1')
                ->name('messages.store');

            /*
             | Receipts are fired on every scroll and every arriving message,
             | so they are throttled far looser than a mutation of their size
             | would normally be. Both are idempotent no-ops when the
             | watermark is already ahead.
             */
            Route::post('read', [V1Controller::class, 'markConversationRead'])
                ->middleware('throttle:240,1')
                ->name('read');

            Route::post('delivered', [V1Controller::class, 'markConversationDelivered'])
                ->middleware('throttle:240,1')
                ->name('delivered');

            Route::post('accept', [V1Controller::class, 'acceptConversation'])->name('accept');
        });

        Route::delete('messages/{uuid}', [V1Controller::class, 'deleteMessage'])
            ->name('messages.destroy');

        /*
         | Presence heartbeat. Runs every 45 seconds for every foregrounded
         | app, and writes one indexed column.
         */
        Route::post('presence/ping', [V1Controller::class, 'presencePing'])
            ->middleware('throttle:120,1')
            ->name('presence.ping');


"""

if "'conversations'" in s:
    print(f'{P}: already patched')
elif ROUTES_ANCHOR not in s:
    sys.exit(f'{P}: profile anchor not found')
else:
    s = s.replace(ROUTES_ANCHOR, CHAT_ROUTES + ROUTES_ANCHOR, 1)
    write(P, s)
    changed.append(P)
    print(f'{P}: chat routes added')


# ========================================================== controller

P = 'app/Http/Controllers/Api/V1/V1Controller.php'
s = read(P)

if 'ChatService' in s:
    print(f'{P}: already patched')
    print('\ndone: ' + (', '.join(changed) if changed else 'nothing to do'))
    sys.exit(0)

# ---- imports ----------------------------------------------------------

pairs = [
    (
        "use App\\Http\\Requests\\Api\\V1\\Posts\\CreatePostRequest;",
        "use App\\Http\\Requests\\Api\\V1\\Chat\\ReceiptRequest;\n"
        "use App\\Http\\Requests\\Api\\V1\\Chat\\SendMessageRequest;\n"
        "use App\\Http\\Requests\\Api\\V1\\Chat\\StartConversationRequest;\n"
        "use App\\Http\\Requests\\Api\\V1\\Posts\\CreatePostRequest;",
    ),
    (
        "use App\\Models\\OtpCode;",
        "use App\\Models\\ConversationParticipant;\n"
        "use App\\Models\\Message;\n"
        "use App\\Models\\OtpCode;",
    ),
    (
        "use App\\Services\\Otp\\Exceptions\\OtpException;",
        "use App\\Services\\Chat\\ChatService;\n"
        "use App\\Services\\Chat\\PresenceService;\n"
        "use App\\Services\\Chat\\ReceiptService;\n"
        "use App\\Services\\Otp\\Exceptions\\OtpException;",
    ),
]

for old, new in pairs:
    if old not in s:
        sys.exit(f'{P}: import anchor missing -> {old}')
    s = s.replace(old, new, 1)

# ---- constructor ------------------------------------------------------

OLD_CTOR = """        private readonly PostService $posts,
    ) {
    }"""

NEW_CTOR = """        private readonly PostService $posts,
        private readonly ChatService $chat,
        private readonly ReceiptService $receipts,
        private readonly PresenceService $presence,
    ) {
    }"""

if OLD_CTOR not in s:
    sys.exit(f'{P}: constructor not found')

s = s.replace(OLD_CTOR, NEW_CTOR, 1)

# ---- methods ----------------------------------------------------------

MEDIA_ANCHOR = """    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
"""

CHAT_METHODS = '''    /*
    |--------------------------------------------------------------------------
    | Chat
    |--------------------------------------------------------------------------
    |
    | No broadcasting yet. These endpoints are the whole of the feature in
    | this phase, on purpose: if the conversation works over plain HTTP with
    | a pull to refresh, then the websocket that follows is an accelerator
    | rather than a load-bearing part, and a dropped socket costs latency
    | instead of messages.
    |
    */

    /**
     * GET /api/v1/conversations?state=accepted|pending&page=1
     *
     * The inbox. `state=pending` is the Requests tab — the same query, the
     * same shape, a different set of threads.
     */
    public function conversations(Request $request): JsonResponse
    {
        $state = $request->string('state', ConversationParticipant::STATE_ACCEPTED)->toString();

        abort_unless(
            in_array($state, [
                ConversationParticipant::STATE_ACCEPTED,
                ConversationParticipant::STATE_PENDING,
            ], true),
            422,
            'Unknown conversation state.',
        );

        return $this->ok(
            $this->chat->inbox(
                $request->user(),
                $state,
                max(1, (int) $request->integer('page', 1)),
                (int) $request->integer('per_page', ChatService::INBOX_PER_PAGE),
            ),
            'OK',
        );
    }

    /**
     * GET /api/v1/conversations/unread-count
     *
     * Polled on cold start and whenever the app returns to the foreground,
     * so it is deliberately one grouped query over an indexed column.
     */
    public function chatUnreadCount(Request $request): JsonResponse
    {
        return $this->ok($this->chat->unreadSummary($request->user()), 'OK');
    }

    /**
     * POST /api/v1/conversations   {user_id}
     *
     * Opens the thread with somebody, creating it only if there is not one
     * already. Safe to call every time the Message button is tapped — the
     * pair key makes a second call return the first call's thread.
     */
    public function startConversation(StartConversationRequest $request): JsonResponse
    {
        $me = $request->user();

        $conversation = $this->chat->findOrCreateDirect(
            $me,
            $this->findUser($request->targetUuid()),
        );

        return $this->ok(
            $this->chat->presentConversation(
                $me,
                $this->chat->findConversation($me, $conversation->uuid),
            ),
            'OK',
        );
    }

    /**
     * GET /api/v1/conversations/{uuid}
     */
    public function showConversation(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        return $this->ok(
            $this->chat->presentConversation($me, $this->chat->findConversation($me, $uuid)),
            'OK',
        );
    }

    /**
     * GET /api/v1/conversations/{uuid}/messages?before=&after=&limit=
     *
     * `before` walks back through history as the user scrolls up. `after`
     * fills the gap left by a dropped connection — the client passes the
     * newest sequence number it already holds and receives everything since.
     *
     * Cursors are per-conversation sequence numbers starting at 1, so they
     * order the thread without exposing anything about the database.
     */
    public function conversationMessages(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        return $this->ok(
            $this->chat->history(
                $me,
                $this->chat->findConversation($me, $uuid),
                $request->filled('before') ? (int) $request->integer('before') : null,
                $request->filled('after') ? (int) $request->integer('after') : null,
                (int) $request->integer('limit', Message::PER_PAGE),
            ),
            'OK',
        );
    }

    /**
     * POST /api/v1/conversations/{uuid}/messages   {client_uuid, body}
     *
     * Idempotent on client_uuid: a retry after a timeout returns the original
     * message rather than creating a second one, and returns it as a success,
     * because from the sender's point of view the message was sent.
     */
    public function sendMessage(SendMessageRequest $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        $result = $this->chat->send(
            $me,
            $this->chat->findConversation($me, $uuid),
            $request->payload(),
        );

        // 200 on a replay, 201 on a genuinely new message. The client keys off
        // client_uuid either way, so the distinction is for logs and for
        // anyone reading the network tab.
        return $result['replayed']
            ? $this->ok($result['message'], 'Already sent.')
            : $this->created($result['message'], 'Sent.');
    }

    /**
     * POST /api/v1/conversations/{uuid}/read   {message_id}
     *
     * Moves the read watermark, never backwards. Safe to fire on every scroll
     * and every arriving message.
     */
    public function markConversationRead(ReceiptRequest $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        return $this->ok(
            $this->receipts->markRead(
                $me,
                $this->chat->findConversation($me, $uuid),
                $request->messageUuid(),
            ),
            'OK',
        );
    }

    /**
     * POST /api/v1/conversations/{uuid}/delivered   {message_id}
     */
    public function markConversationDelivered(ReceiptRequest $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        return $this->ok(
            $this->receipts->markDelivered(
                $me,
                $this->chat->findConversation($me, $uuid),
                $request->messageUuid(),
            ),
            'OK',
        );
    }

    /**
     * POST /api/v1/conversations/{uuid}/accept
     */
    public function acceptConversation(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        return $this->ok(
            $this->chat->accept($me, $this->chat->findConversation($me, $uuid)),
            'Request accepted.',
        );
    }

    /**
     * DELETE /api/v1/conversations/{uuid}
     *
     * Leaves the thread, or declines a request. The membership row survives
     * so a later message reopens the same conversation rather than starting a
     * second one beside it.
     */
    public function leaveConversation(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        $this->chat->leave($me, $this->chat->findConversation($me, $uuid));

        return $this->ok(null, 'Conversation removed.');
    }

    /**
     * DELETE /api/v1/messages/{uuid}
     *
     * Delete for everyone. Soft, so the other person's client can show a
     * tombstone instead of losing a line out of the middle of the thread.
     */
    public function deleteMessage(Request $request, string $uuid): JsonResponse
    {
        $this->chat->deleteMessage($request->user(), $this->findMessage($uuid));

        return $this->ok(null, 'Message deleted.');
    }

    /**
     * POST /api/v1/presence/ping
     *
     * The heartbeat behind "online" and "last seen". Answers with the
     * interval the client should use, so the window can be widened later
     * without shipping a new build.
     */
    public function presencePing(Request $request): JsonResponse
    {
        return $this->ok($this->presence->ping($request->user()), 'OK');
    }

    private function findMessage(string $uuid): Message
    {
        $message = Message::with('sender:id,uuid')->where('uuid', $uuid)->first();

        abort_if($message === null, 404, 'That message does not exist.');

        return $message;
    }

'''

if MEDIA_ANCHOR not in s:
    sys.exit(f'{P}: media section anchor not found')

s = s.replace(MEDIA_ANCHOR, CHAT_METHODS + MEDIA_ANCHOR, 1)

write(P, s)
changed.append(P)
print(f'{P}: chat endpoints added')

print('\ndone: ' + ', '.join(changed))
