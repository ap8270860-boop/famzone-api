<?php

namespace App\Http\Requests\Api\V1\Chat;

use App\Models\Message;
use App\Services\Chat\AttachmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/uploads   (multipart)
 *
 * Step one of sending a file. Returns an id; step two sends the message that
 * adopts it.
 */
class UploadRequest extends FormRequest
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
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $image = $this->input('type') === Message::TYPE_IMAGE;
        $audio = $this->input('type') === Message::TYPE_AUDIO;

        return [
            'type' => ['required', Rule::in([
                Message::TYPE_IMAGE,
                Message::TYPE_FILE,
                Message::TYPE_AUDIO,
            ])],

            'file' => array_filter([
                'required',
                'file',

                /*
                 | `image` checks the real file signature rather than the
                 | extension, so a renamed script cannot get through as a
                 | photo. Worth more here than on posts: this file is sent
                 | straight to another person's device.
                 */
                $image ? 'image' : null,
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

                'max:'.($image
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => 'That file is too large to send.',
            'file.image' => 'That does not look like an image.',
            'file.mimetypes' => 'That audio format is not supported.',
        ];
    }
}
