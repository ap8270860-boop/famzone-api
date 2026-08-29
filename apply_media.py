#!/usr/bin/env python3
"""Media — images and files on messages.

Run from the famzone-api repo root. Idempotent.
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
            sys.exit(f'{path}: anchor missing ->\n{old[:220]}')

        s = s.replace(old, new, 1)

    write(path, s)
    changed.append(path)
    print(f'{path}: patched')


# ====================================================== MessageAttachment

patch('app/Models/MessageAttachment.php', [
    (
        "#[Fillable(['disk', 'path', 'mime', 'size_bytes', 'width', 'height', 'duration_ms', 'waveform'])]",
        "#[Fillable([\n"
        "    'disk', 'path', 'mime', 'original_name', 'size_bytes',\n"
        "    'width', 'height', 'duration_ms', 'waveform',\n"
        "])]",
    ),
], marker="'original_name'")


# ================================================================ Message

patch('app/Models/Message.php', [
    (
        """    public function isDeleted(): bool""",
        """    /**
     * @return \\Illuminate\\Database\\Eloquent\\Relations\\HasOne<MessageAttachment>
     */
    public function attachment(): \\Illuminate\\Database\\Eloquent\\Relations\\HasOne
    {
        return $this->hasOne(MessageAttachment::class);
    }

    /** Whether this message is carrying a file rather than text. */
    public function hasMedia(): bool
    {
        return in_array($this->type, [
            self::TYPE_IMAGE,
            self::TYPE_FILE,
            self::TYPE_AUDIO,
        ], true);
    }

    public function isDeleted(): bool""",
    ),
], marker='public function attachment()')


# ===================================================== SendMessageRequest

patch('app/Http/Requests/Api/V1/Chat/SendMessageRequest.php', [
    (
        """            // Only text is accepted until the media phase. The column already
            // knows about the other types; the endpoint does not yet.
            'type' => ['sometimes', Rule::in([Message::TYPE_TEXT])],

            'body' => ['required', 'string', 'min:1', 'max:'.Message::BODY_MAX],""",
        """            'type' => ['sometimes', Rule::in([
                Message::TYPE_TEXT,
                Message::TYPE_IMAGE,
                Message::TYPE_FILE,
                Message::TYPE_AUDIO,
            ])],

            /*
             | Text is required for a text message and optional for anything
             | else, where it becomes the caption. A photo with nothing
             | written under it is a perfectly ordinary message; an empty text
             | message is not.
             */
            'body' => [
                Rule::requiredIf(fn () => $this->input('type', Message::TYPE_TEXT) === Message::TYPE_TEXT),
                'nullable', 'string', 'max:'.Message::BODY_MAX,
            ],

            // The id returned by POST /uploads. Required for every type but
            // text — the service checks it belongs to the sender and has not
            // already been used.
            'upload_id' => [
                Rule::requiredIf(fn () => $this->input('type', Message::TYPE_TEXT) !== Message::TYPE_TEXT),
                'nullable', 'uuid',
            ],""",
    ),
    (
        """    /**
     * @return array{client_uuid: string, type: string, body: ?string}
     */
    public function payload(): array
    {
        return [
            'client_uuid' => (string) $this->input('client_uuid'),
            'type' => (string) $this->input('type'),
            'body' => $this->input('body'),
        ];
    }""",
        """    /**
     * @return array{client_uuid: string, type: string, body: ?string, upload_id: ?string}
     */
    public function payload(): array
    {
        return [
            'client_uuid' => (string) $this->input('client_uuid'),
            'type' => (string) $this->input('type'),
            'body' => $this->input('body'),
            'upload_id' => $this->input('upload_id'),
        ];
    }""",
    ),
], marker='upload_id')


# ============================================================ ChatService

patch('app/Services/Chat/ChatService.php', [
    (
        "use App\\Models\\User;\nuse App\\Services\\Social\\RelationshipService;",
        "use App\\Models\\MessageAttachment;\n"
        "use App\\Models\\User;\n"
        "use App\\Services\\Social\\RelationshipService;",
    ),
    (
        """    public function __construct(
        private readonly RelationshipService $relationships,
        private readonly PresenceService $presence,
    ) {
    }""",
        """    public function __construct(
        private readonly RelationshipService $relationships,
        private readonly PresenceService $presence,
        private readonly AttachmentService $attachments,
    ) {
    }""",
    ),

    # --- adopt the upload inside the send transaction ------------------
    (
        """        $message->conversation_id = $conversation->id;
        $message->sender_id = $sender->id;
        $message->seq = $seq;
        $message->save();""",
        """        $message->conversation_id = $conversation->id;
        $message->sender_id = $sender->id;
        $message->seq = $seq;
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
        }""",
    ),

    # --- present it ----------------------------------------------------
    (
        """            'deleted' => $deleted,
            'edited_at' => $message->edited_at?->toIso8601String(),""",
        """            'deleted' => $deleted,

            /*
             | Dropped entirely once deleted, along with the body. "Deleted"
             | has to mean deleted — leaving a live signed link behind would
             | make the word a lie, and the file is still on disk.
             */
            'attachment' => $deleted || $message->attachment === null
                ? null
                : $this->attachments->present($message->attachment),

            'edited_at' => $message->edited_at?->toIso8601String(),""",
    ),

    # --- eager load it everywhere messages are read --------------------
    (
        "        $query = $conversation->messages()\n"
        "            ->withTrashed()\n"
        "            ->with('sender:id,uuid');",
        "        $query = $conversation->messages()\n"
        "            ->withTrashed()\n"
        "            ->with(['sender:id,uuid', 'attachment']);",
    ),
    (
        """        return Message::withTrashed()
            ->with('sender:id,uuid')
            ->where('conversation_id', $conversation->id)
            ->where('client_uuid', $clientUuid)
            ->first();""",
        """        return Message::withTrashed()
            ->with(['sender:id,uuid', 'attachment'])
            ->where('conversation_id', $conversation->id)
            ->where('client_uuid', $clientUuid)
            ->first();""",
    ),
    (
        "            ->with(['participants.user', 'lastMessage.sender:id,uuid'])\n"
        "            ->forPage($page, $perPage)",
        "            ->with([\n"
        "                'participants.user',\n"
        "                'lastMessage.sender:id,uuid',\n"
        "                'lastMessage.attachment',\n"
        "            ])\n"
        "            ->forPage($page, $perPage)",
    ),
], marker='AttachmentService')


# ============================================================ MessageSent

patch('app/Events/Chat/MessageSent.php', [
    (
        "            $this->message->loadMissing('sender:id,uuid'),",
        "            $this->message->loadMissing(['sender:id,uuid', 'attachment']),",
    ),
], marker="'attachment'")


# ================================================================ routes

patch('routes/api.php', [
    (
        """    /*
     | Public authentication.""",
        """    /*
     | Signed chat media. Same contract as the avatar and post routes above:
     | the signature in the query string is the credential, so the link works
     | inside a plain <img> tag.
     */
    Route::get('media/chat/{uuid}', [V1Controller::class, 'streamAttachment'])
        ->middleware('signed')
        ->name('media.chat');

    /*
     | Public authentication.""",
    ),
    (
        """        Route::post('conversations', [V1Controller::class, 'startConversation'])""",
        """        /*
         | Step one of sending a file. Throttled hard — this is the most
         | expensive endpoint in the API, and the only one where a single
         | request can cost 25 MB of disk.
         */
        Route::post('uploads', [V1Controller::class, 'uploadAttachment'])
            ->middleware('throttle:30,1')
            ->name('uploads.store');

        Route::post('conversations', [V1Controller::class, 'startConversation'])""",
    ),
], marker='uploads.store')


# ============================================================ controller

patch('app/Http/Controllers/Api/V1/V1Controller.php', [
    (
        "use App\\Http\\Requests\\Api\\V1\\Chat\\StartConversationRequest;",
        "use App\\Http\\Requests\\Api\\V1\\Chat\\StartConversationRequest;\n"
        "use App\\Http\\Requests\\Api\\V1\\Chat\\UploadRequest;",
    ),
    (
        "use App\\Models\\Message;\nuse App\\Models\\OtpCode;",
        "use App\\Models\\Message;\n"
        "use App\\Models\\MessageAttachment;\n"
        "use App\\Models\\OtpCode;",
    ),
    (
        "use App\\Services\\Chat\\ChatService;",
        "use App\\Services\\Chat\\AttachmentService;\n"
        "use App\\Services\\Chat\\ChatService;",
    ),
    (
        "        private readonly PresenceService $presence,\n    ) {",
        "        private readonly PresenceService $presence,\n"
        "        private readonly AttachmentService $attachments,\n"
        "    ) {",
    ),
    (
        """    private function findMessage(string $uuid): Message""",
        """    /**
     * POST /api/v1/uploads   (multipart: file, type)
     *
     * Step one of sending a file. Answers with an id; step two is a normal
     * send carrying `upload_id`.
     *
     * Two steps rather than one fat request: a 25 MB file has no business
     * holding a chat request open, and — more to the point — the message row
     * is not written until the bytes have landed. A message broadcast ahead
     * of its file shows every recipient a broken attachment.
     *
     * An upload that is never sent is harmless; `chat:prune-uploads` sweeps
     * it up after a day.
     */
    public function uploadAttachment(UploadRequest $request): JsonResponse
    {
        $attachment = $this->attachments->upload(
            $request->user(),
            $request->file('file'),
            (string) $request->input('type'),
        );

        return $this->created(
            $this->attachments->present($attachment),
            'Uploaded.',
        );
    }

    /**
     * GET /api/v1/media/chat/{uuid}   (signed)
     *
     * Streams an attachment. Not behind auth:sanctum on purpose — the
     * signature is the credential, which is what lets the URL go straight
     * into an <img> tag.
     */
    public function streamAttachment(Request $request, string $uuid): StreamedResponse
    {
        $attachment = MessageAttachment::where('uuid', $uuid)->first();

        abort_if($attachment === null, 404);

        $disk = Storage::disk($attachment->disk ?: config('filesystems.default'));

        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response($attachment->path, $attachment->original_name, [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function findMessage(string $uuid): Message""",
    ),
    (
        "        $message = Message::with('sender:id,uuid')->where('uuid', $uuid)->first();",
        "        $message = Message::with(['sender:id,uuid', 'attachment'])\n"
        "            ->where('uuid', $uuid)\n"
        "            ->first();",
    ),
], marker='uploadAttachment')


print('\ndone: ' + (', '.join(changed) if changed else 'nothing to do'))
