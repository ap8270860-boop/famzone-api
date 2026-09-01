#!/usr/bin/env python3
"""Fix the voice-note mime check.

finfo reports an m4a/AAC file as video/mp4 more often than not — the container
is an MP4 and the header does not say "this one only has audio in it". The
allowlist that shipped did not include it, so every voice note was rejected.

Run from the famzone-api repo root. Idempotent and guarded.
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
            sys.exit(f'{path}: anchor missing ->\n{old[:200]}')

        s = s.replace(old, new, 1)

    write(path, s)
    changed.append(path)
    print(f'{path}: patched')


patch('app/Http/Requests/Api/V1/Chat/UploadRequest.php', [
    (
        "class UploadRequest extends FormRequest\n{",
        """class UploadRequest extends FormRequest
{
    /**
     * What a voice note is allowed to be.
     *
     * The point of this list is to catch a codec mistake, not an attacker: a
     * voice note has to be AAC in an MP4 container, because that is the one
     * encoding both platforms record *and* play. Opus in CAF (iOS) and Opus
     * in Ogg (Android) are both silence on the other platform, and both are
     * still refused here.
     *
     * video/mp4 is in the list on purpose. finfo identifies the container,
     * and an m4a *is* an MP4 container — it usually cannot tell that the only
     * track inside is audio. Leaving it out rejected every voice note the app
     * recorded.
     *
     * @var array<int, string>
     */
    public const AUDIO_MIMES = [
        'audio/mp4',
        'audio/x-m4a',
        'audio/m4a',
        'audio/aac',
        'audio/aacp',
        'audio/mpeg',
        'application/mp4',
        'video/mp4',
    ];
""",
    ),
    (
        "                $audio ? 'mimetypes:audio/mp4,audio/x-m4a,audio/aac,audio/mpeg' : null,",
        """                $audio ? static function (string $attribute, $value, callable $fail): void {
                    $mime = $value instanceof UploadedFile
                        ? (string) $value->getMimeType()
                        : '';

                    if (! in_array($mime, self::AUDIO_MIMES, true)) {
                        // Naming the type it actually saw, because "not
                        // supported" with no detail is a bug report nobody
                        // can act on.
                        $fail("That audio format is not supported ({$mime}).");
                    }
                } : null,""",
    ),
    (
        "use Illuminate\\Foundation\\Http\\FormRequest;",
        "use Illuminate\\Foundation\\Http\\FormRequest;\nuse Illuminate\\Http\\UploadedFile;",
    ),
    (
        "            'file.mimetypes' => 'That audio format is not supported.',\n",
        "",
    ),
], marker='AUDIO_MIMES')


print('\ndone: ' + (', '.join(changed) if changed else 'nothing to do'))
