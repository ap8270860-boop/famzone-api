#!/usr/bin/env python3
"""Group chats, server side.

Teaches the chat pipeline that a conversation can hold more than two people.
Sending, history and receipts are untouched — a group message is an ordinary
message — but everything that says "the other person" needs a second reading.

Run from the famzone-api repo root. Idempotent, and every anchor is guarded.
"""

import io
import sys

changed = []


def read(p):
    return io.open(p, encoding='utf-8').read()


def write(p, s):
    io.open(p, 'w', encoding='utf-8', newline='').write(s)


def patch(path, pairs, marker):
    s = read(path)

    if marker in s:
        print(f'{path}: already patched')

        return

    for old, new in pairs:
        if old not in s:
            sys.exit(f'{path}: anchor missing ->\n{old[:240]}')

        s = s.replace(old, new, 1)

    write(path, s)
    changed.append(path)
    print(f'{path}: patched')


# ========================================================== Conversation

patch('app/Models/Conversation.php', [
    (
        "#[Fillable(['type', 'pair_key'])]",
        "#[Fillable(['type', 'pair_key', 'title'])]",
    ),
    (
        """    public function isDirect(): bool
    {
        return $this->type === self::TYPE_DIRECT;
    }""",
        """    public function isDirect(): bool
    {
        return $this->type === self::TYPE_DIRECT;
    }

    public function isGroup(): bool
    {
        return $this->type === self::TYPE_GROUP;
    }""",
    ),
], marker='public function isGroup()')


# ================================================ ConversationParticipant

patch('app/Models/ConversationParticipant.php', [
    (
        "    public const STATE_PENDING = 'pending';",
        "    public const ROLE_ADMIN = 'admin';\n"
        "    public const ROLE_MEMBER = 'member';\n"
        "\n"
        "    public const STATE_PENDING = 'pending';",
    ),
    (
        """    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }""",
        """    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }""",
    ),
], marker='ROLE_ADMIN')


# ============================================================ ChatService

patch('app/Services/Chat/ChatService.php', [
    (
        "use App\\Models\\Message;\n",
        "use App\\Models\\Message;\n",
    ),

    # The inbox must not hide a group because one member is blocked.
    (
        """            // Blocking hides the thread without destroying it.
            ->whereDoesntHave('participants', fn (Builder $q) => $q
                ->where('user_id', '!=', $me->id)
                ->whereIn('user_id', Block::wallIds($me->id)))""",
        """            /*
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
                    ->whereIn('user_id', Block::wallIds($me->id))))""",
    ),

    # Sending: the two-person checks apply to two-person threads.
    (
        """        $mine = $this->participantOrFail($conversation, $sender);
        $other = $this->otherParticipant($conversation, $sender);

        abort_if($other === null, 422, 'That conversation has nobody in it.');

        abort_unless(
            $this->canMessage($sender, $other->user),
            403,
            'You cannot message this account.',
        );""",
        """        $mine = $this->participantOrFail($conversation, $sender);

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
        }""",
    ),
    (
        "        $this->guardRequestCap($conversation, $sender, $other);",
        "        if ($other !== null) {\n"
        "            $this->guardRequestCap($conversation, $sender, $other);\n"
        "        }",
    ),
    (
        """    private function persist(
        User $sender,
        Conversation $conversation,
        ConversationParticipant $other,
        array $payload,
    ): Message {""",
        """    private function persist(
        User $sender,
        Conversation $conversation,
        ?ConversationParticipant $other,
        array $payload,
    ): Message {""",
    ),
    (
        """        $reopen = $other->hasLeft()
            ? ['left_at' => null, 'state' => $this->stateFor($other->user, $sender)]
            : [];""",
        """        /*
         | A message reopens a direct thread the other person had left.
         |
         | Not a group: leaving a group is a decision the room watched you
         | make, and a message from somebody else must not quietly put you
         | back in it.
         */
        $reopen = $other !== null && $other->hasLeft()
            ? ['left_at' => null, 'state' => $this->stateFor($other->user, $sender)]
            : [];""",
    ),

    # Everybody else's unread goes up — in a group that is everybody who
    # has not left, which the existing query already expresses correctly.
    (
        """        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $sender->id)
            ->update(array_merge($reopen, [
                'unread_count' => DB::raw('unread_count + 1'),
                'updated_at' => now(),
            ]));""",
        """        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $sender->id)
            // In a group, only the people still in it.
            ->when($conversation->isGroup(), fn ($q) => $q->whereNull('left_at'))
            ->update(array_merge($reopen, [
                'unread_count' => DB::raw('unread_count + 1'),
                'updated_at' => now(),
            ]));""",
    ),

    # The payload.
    (
        """        $mine = $conversation->participants->firstWhere('user_id', $me->id);
        $other = $conversation->participants->firstWhere(
            fn (ConversationParticipant $p) => $p->user_id !== $me->id,
        );""",
        """        $mine = $conversation->participants->firstWhere('user_id', $me->id);

        // Null for a group, where there is no "other person" to be the
        // subject of the row. The group block below carries what a group row
        // needs instead — its name, its picture, and how many are in it.
        $other = $conversation->isGroup() ? null : $conversation->participants->firstWhere(
            fn (ConversationParticipant $p) => $p->user_id !== $me->id,
        );""",
    ),
    (
        "            'pinned' => $mine?->pinned_at !== null,",
        "            'pinned' => $mine?->pinned_at !== null,\n"
        "\n"
        "            // Null on a direct thread, so a client can tell the two\n"
        "            // apart from one field rather than from `type`.\n"
        "            'group' => $conversation->isGroup()\n"
        "                ? app(GroupService::class)->present($me, $conversation, $withMembers)\n"
        "                : null,",
    ),
    (
        """    public function presentConversation(User $me, Conversation $conversation): array
    {""",
        """    public function presentConversation(
        User $me,
        Conversation $conversation,
        bool $withMembers = false,
    ): array {""",
    ),
], marker="'group' => \\$conversation->isGroup()".replace('\\', ''))



# ============================================================== channels

patch('routes/channels.php', [
    (
        """    $others = $conversation->participants
        ->where('user_id', '!=', $user->id)
        ->pluck('user_id');""",
        """    /*
     | The wall check is about a pair, so it only applies to a pair.
     |
     | In a group, two members having blocked each other is their business:
     | neither is removed from the room, and neither is cut off from everybody
     | else in it. Applying the direct-thread rule here would drop somebody
     | out of a group conversation the moment one member blocked them.
     */
    if ($conversation->isGroup()) {
        return true;
    }

    $others = $conversation->participants
        ->where('user_id', '!=', $user->id)
        ->pluck('user_id');""",
    ),
], marker='$conversation->isGroup()')


# ================================================================ routes

patch('routes/api.php', [
    (
        """    Route::get('media/chat/{uuid}', [V1Controller::class, 'streamAttachment'])""",
        """    Route::get('media/group/{uuid}', [V1Controller::class, 'streamGroupAvatar'])
        ->middleware('signed')
        ->name('media.group');

    Route::get('media/chat/{uuid}', [V1Controller::class, 'streamAttachment'])""",
    ),
    (
        """        Route::get('conversations', [V1Controller::class, 'conversations'])""",
        """        /*
         | Groups.
         |
         | Both before conversations/{uuid} so the literal segments are not
         | swallowed by the parameter.
         */
        Route::get('conversations/group-candidates', [V1Controller::class, 'groupCandidates'])
            ->middleware('throttle:60,1')
            ->name('conversations.candidates');

        Route::post('conversations/group', [V1Controller::class, 'createGroup'])
            ->middleware('throttle:20,1')
            ->name('conversations.group');

        Route::get('conversations', [V1Controller::class, 'conversations'])""",
    ),
], marker='conversations.candidates')


# ============================================================ controller

patch('app/Http/Controllers/Api/V1/V1Controller.php', [
    (
        "use App\\Services\\Chat\\MessageActionService;",
        "use App\\Services\\Chat\\GroupService;\nuse App\\Services\\Chat\\MessageActionService;",
    ),
    (
        "        private readonly ThreadSettingsService $threads,\n    ) {",
        "        private readonly ThreadSettingsService $threads,\n"
        "        private readonly GroupService $groups,\n"
        "    ) {",
    ),
    (
        """    /**
     * GET /api/v1/conversations/unread-count""",
        """    /**
     * GET /api/v1/conversations/group-candidates?scope=connections|family
     *
     * Who this person may put in a group: everyone they follow or who
     * follows them plus their family, or family alone.
     */
    public function groupCandidates(Request $request): JsonResponse
    {
        $scope = $request->string('scope', GroupService::SCOPE_CONNECTIONS)->toString();

        abort_unless(
            in_array($scope, [GroupService::SCOPE_CONNECTIONS, GroupService::SCOPE_FAMILY], true),
            422,
            'Unknown scope.',
        );

        return $this->ok($this->groups->candidates($request->user(), $scope), 'OK');
    }

    /**
     * POST /api/v1/conversations/group   (multipart)
     *
     * {title, member_ids[], scope, avatar?}
     *
     * Multipart because the picture is chosen in the same step as the name —
     * a two-request create would leave a group with no photo whenever the
     * second one failed.
     */
    public function createGroup(CreateGroupRequest $request): JsonResponse
    {
        $me = $request->user();

        $conversation = $this->groups->create(
            $me,
            $request->title(),
            $request->memberIds(),
            $request->scope(),
            $request->file('avatar'),
        );

        return $this->created(
            $this->chat->presentConversation($me, $conversation, withMembers: true),
            'Group created.',
        );
    }

    /**
     * GET /api/v1/media/group/{uuid}   (signed)
     *
     * Streams a group picture. Not behind auth:sanctum — the signature is the
     * credential, which is what lets the URL go straight into an <img> tag.
     */
    public function streamGroupAvatar(Request $request, string $uuid): StreamedResponse
    {
        $conversation = Conversation::where('uuid', $uuid)->first();

        abort_if($conversation === null || blank($conversation->avatar_path), 404);

        $disk = Storage::disk(config('filesystems.default'));

        abort_unless($disk->exists($conversation->avatar_path), 404);

        return $disk->response($conversation->avatar_path);
    }

    /**
     * GET /api/v1/conversations/unread-count""",
    ),

    # The single-conversation view carries the member list; the inbox does not.
    (
        """        return $this->ok(
            $this->chat->presentConversation($me, $this->chat->findConversation($me, $uuid)),
            'OK',
        );""",
        """        return $this->ok(
            // Members only on the single-thread view. An inbox page of twenty
            // groups has no business carrying every member of each of them.
            $this->chat->presentConversation(
                $me,
                $this->chat->findConversation($me, $uuid),
                withMembers: true,
            ),
            'OK',
        );""",
    ),

    # Leaving a group is a different act from leaving a direct thread.
    (
        """        $this->chat->leave($me, $this->chat->findConversation($me, $uuid));

        return $this->ok(null, 'Conversation removed.');""",
        """        $conversation = $this->chat->findConversation($me, $uuid);

        /*
         | Two different acts behind one endpoint.
         |
         | Leaving a direct thread keeps the row so a later message reopens
         | it. Leaving a group is a fact the room can see, and nothing pulls
         | you back in on its own.
         */
        if ($conversation->isGroup()) {
            $this->groups->leave($me, $conversation);

            return $this->ok(null, 'You left the group.');
        }

        $this->chat->leave($me, $conversation);

        return $this->ok(null, 'Conversation removed.');""",
    ),
], marker='createGroup')


print('\ndone: ' + (', '.join(changed) if changed else 'nothing to do'))
