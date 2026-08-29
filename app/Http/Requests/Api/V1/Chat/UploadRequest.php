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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $image = $this->input('type') === Message::TYPE_IMAGE;

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

                'max:'.($image
                    ? AttachmentService::MAX_IMAGE_KB
                    : AttachmentService::MAX_FILE_KB),
            ]),
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
        ];
    }
}
