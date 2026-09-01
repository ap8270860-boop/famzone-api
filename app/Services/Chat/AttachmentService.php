<?php

namespace App\Services\Chat;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Files attached to messages.
 *
 * Uploading is deliberately its own step, ahead of sending. Two reasons, and
 * the second is the one that matters:
 *
 *  - a large file must not hold a chat request open, and a failed upload must
 *    not leave a half-written message behind;
 *  - the message row is not created until the bytes have landed. Broadcasting
 *    a message that points at a file which does not exist yet gives every
 *    recipient a broken attachment and no way to recover.
 *
 * So `upload()` writes the file and an unattached row, and `attach()` adopts
 * that row when the message is finally sent. An upload that is never sent is
 * an orphan, swept up by `chat:prune-uploads`.
 */
class AttachmentService
{
    /** Images, capped smaller than files because they are re-encoded anyway. */
    public const MAX_IMAGE_KB = 8192;

    /** Everything else. */
    public const MAX_FILE_KB = 25600;

    /** How long an unattached upload survives before it is swept. */
    public const ORPHAN_HOURS = 24;

    /**
     * Store a file and record it, unattached.
     */
    /**
     * @param  array<int, int>|null  $waveform
     */
    public function upload(
        User $uploader,
        UploadedFile $file,
        string $type,
        ?int $durationMs = null,
        ?array $waveform = null,
    ): MessageAttachment {
        $disk = Storage::disk(config('filesystems.default'));

        /*
         | Namespaced by uploader and named with a fresh uuid.
         |
         | Never the original filename: it arrives from a client, may collide
         | with somebody else's, and can carry path separators or characters
         | the filesystem treats specially. The real name is kept in the
         | database where it is data rather than a path.
         */
        $path = "chat/{$uploader->uuid}/".Str::uuid7().'.'.($file->extension() ?: 'bin');

        $disk->putFileAs(dirname($path), $file, basename($path), [
            'visibility' => 'private',
        ]);

        [$width, $height] = $this->dimensions($file, $type);

        $attachment = new MessageAttachment([
            'disk' => config('filesystems.default'),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'width' => $width,
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
        ]);

        $attachment->user_id = $uploader->id;
        $attachment->original_name = $this->safeName($file);
        $attachment->save();

        return $attachment;
    }

    /**
     * Adopt an uploaded file into a message.
     *
     * Checks ownership and that it has not already been used. Without the
     * first, anybody could attach somebody else's upload to their own message
     * by guessing an id; without the second, one upload could be re-sent into
     * a conversation the uploader is no longer in.
     */
    public function attach(User $sender, Message $message, string $uploadUuid): MessageAttachment
    {
        $attachment = MessageAttachment::where('uuid', $uploadUuid)->first();

        abort_if($attachment === null, 404, 'That upload no longer exists.');

        abort_unless(
            $attachment->user_id === $sender->id,
            403,
            'That upload belongs to somebody else.',
        );

        abort_unless(
            $attachment->message_id === null,
            409,
            'That upload has already been sent.',
        );

        $attachment->message_id = $message->id;
        $attachment->save();

        return $attachment;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(MessageAttachment $attachment): array
    {
        return [
            'id' => $attachment->uuid,
            'mime' => $attachment->mime,
            'name' => $attachment->original_name,
            'size_bytes' => $attachment->size_bytes,
            'width' => $attachment->width,
            'height' => $attachment->height,
            'duration_ms' => $attachment->duration_ms,
            'waveform' => $attachment->waveform,
            'url' => $this->url($attachment),
        ];
    }

    /**
     * A signed, expiring link.
     *
     * Same reasoning as avatars and post images: chat media lives on a private
     * disk that nginx does not serve, because a photo sent to one person must
     * not sit behind a URL anyone can fetch. The signature is the credential,
     * which is what lets the link work inside a plain <img> tag — an image
     * cannot send an Authorization header.
     */
    public function url(MessageAttachment $attachment): ?string
    {
        if (blank($attachment->path)) {
            return null;
        }

        $disk = Storage::disk($attachment->disk ?: config('filesystems.default'));

        try {
            if ($disk->providesTemporaryUrls()) {
                return $disk->temporaryUrl(
                    $attachment->path,
                    now()->addHours(User::MEDIA_LINK_HOURS),
                );
            }
        } catch (\Throwable) {
            // Fall through to the streaming route.
        }

        return URL::temporarySignedRoute(
            'api.v1.media.chat',
            now()->addHours(User::MEDIA_LINK_HOURS),
            ['uuid' => $attachment->uuid],
        );
    }

    /**
     * Delete an upload and its bytes.
     */
    public function discard(MessageAttachment $attachment): void
    {
        try {
            Storage::disk($attachment->disk ?: config('filesystems.default'))
                ->delete($attachment->path);
        } catch (\Throwable) {
            // A missing file is the state we wanted anyway.
        }

        $attachment->delete();
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function dimensions(UploadedFile $file, string $type): array
    {
        if ($type !== Message::TYPE_IMAGE) {
            return [null, null];
        }

        $size = @getimagesize($file->getRealPath());

        // Recorded so a bubble can reserve the right box before the bytes
        // arrive — the same trick the post grid uses. Null is survivable; the
        // client falls back to a square placeholder.
        return $size === false ? [null, null] : [$size[0], $size[1]];
    }

    /**
     * The original filename, made safe to store and display.
     *
     * Kept for documents, where "Rent agreement.pdf" is the whole point of
     * the attachment. Stripped of any path and capped, because it arrives
     * from a client and is later rendered in a UI.
     */
    private function safeName(UploadedFile $file): string
    {
        $name = basename((string) $file->getClientOriginalName());

        return Str::limit(trim($name) ?: 'file', 120, '');
    }
}
