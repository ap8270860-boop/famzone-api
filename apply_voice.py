#!/usr/bin/env python3
"""Voice notes, server side.

The columns already exist — duration_ms and waveform were put on
message_attachments when media was built, and present() already returns both.
All that is missing is accepting them on upload and writing them down.

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


# ========================================================== UploadRequest

patch('app/Http/Requests/Api/V1/Chat/UploadRequest.php', [
    (
        "        $image = $this->input('type') === Message::TYPE_IMAGE;",
        "        $image = $this->input('type') === Message::TYPE_IMAGE;\n"
        "        $audio = $this->input('type') === Message::TYPE_AUDIO;",
    ),
    (
        """                $image ? 'image' : null,
                $image ? 'mimes:jpg,jpeg,png,webp,heic' : null,
""",
        """                $image ? 'image' : null,
                $image ? 'mimes:jpg,jpeg,png,webp,heic' : null,

                /*
                 | Voice notes are AAC-LC in an m4a container, always.
                 |
                 | Not because the server cares what is inside, but because
                 | it is the one encoding both platforms record *and* play
                 | natively: iOS writes Opus into a CAF container that
                 | Android will not open, so an iPhone note would arrive as
                 | silence. Refusing anything else here means that mistake
                 | shows up on the first upload rather than on somebody
                 | else's phone.
                 */
                $audio ? 'mimetypes:audio/mp4,audio/x-m4a,audio/aac,audio/mpeg' : null,
""",
    ),
    (
        """                'max:'.($image
                    ? AttachmentService::MAX_IMAGE_KB
                    : AttachmentService::MAX_FILE_KB),
            ]),
        ];""",
        """                'max:'.($image
                    ? AttachmentService::MAX_IMAGE_KB
                    : AttachmentService::MAX_FILE_KB),
            ]),

            /*
             | Both measured on the recording device, and both optional so a
             | client that cannot produce them still works.
             |
             | The duration is what a bubble shows before anything is
             | downloaded; the waveform is what it draws. Trusting the client
             | for them is fine — the worst a liar achieves is a wrong-looking
             | picture of their own voice note.
             */
            'duration_ms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3600000'],

            'waveform' => ['sometimes', 'nullable', 'array', 'max:128'],
            'waveform.*' => ['integer', 'min:0', 'max:100'],
        ];""",
    ),
    (
        """            'file.max' => 'That file is too large to send.',
            'file.image' => 'That does not look like an image.',""",
        """            'file.max' => 'That file is too large to send.',
            'file.image' => 'That does not look like an image.',
            'file.mimetypes' => 'That audio format is not supported.',""",
    ),
    (
        """class UploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }""",
        """class UploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Multipart carries everything as a string, so the waveform arrives as
     * JSON text rather than as an array. Decoded before validation so the
     * rules above see the array they are written for.
     */
    protected function prepareForValidation(): void
    {
        $waveform = $this->input('waveform');

        if (is_string($waveform)) {
            $decoded = json_decode($waveform, true);

            $this->merge([
                'waveform' => is_array($decoded) ? $decoded : null,
            ]);
        }
    }

    /**
     * @return array<int, int>|null
     */
    public function waveform(): ?array
    {
        $waveform = $this->input('waveform');

        if (! is_array($waveform) || $waveform === []) {
            return null;
        }

        return array_map(static fn ($v) => (int) $v, array_values($waveform));
    }

    public function durationMs(): ?int
    {
        $duration = $this->input('duration_ms');

        return is_numeric($duration) ? (int) $duration : null;
    }""",
    ),
], marker='durationMs')


# ======================================================= AttachmentService

patch('app/Services/Chat/AttachmentService.php', [
    (
        """    public function upload(User $uploader, UploadedFile $file, string $type): MessageAttachment
    {""",
        """    /**
     * @param  array<int, int>|null  $waveform
     */
    public function upload(
        User $uploader,
        UploadedFile $file,
        string $type,
        ?int $durationMs = null,
        ?array $waveform = null,
    ): MessageAttachment {""",
    ),
    (
        """            'width' => $width,
            'height' => $height,
        ]);""",
        """            'width' => $width,
            'height' => $height,

            /*
             | Voice notes only, and measured on the device that recorded it.
             |
             | Deriving either of these here would mean decoding the audio on
             | the web server — expensive, and pointless when the recorder
             | already had every sample in its hands.
             */
            'duration_ms' => $durationMs,
            'waveform' => $waveform,
        ]);""",
    ),
], marker="'duration_ms' => \\$durationMs".replace('\\', ''))


# ============================================================ controller

patch('app/Http/Controllers/Api/V1/V1Controller.php', [
    (
        """        $attachment = $this->attachments->upload(
            $request->user(),
            $request->file('file'),
            (string) $request->input('type'),
        );""",
        """        $attachment = $this->attachments->upload(
            $request->user(),
            $request->file('file'),
            (string) $request->input('type'),
            $request->durationMs(),
            $request->waveform(),
        );""",
    ),
], marker='$request->durationMs()')


print('\ndone: ' + (', '.join(changed) if changed else 'nothing to do'))
