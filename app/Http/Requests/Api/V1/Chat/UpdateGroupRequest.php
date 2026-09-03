<?php

namespace App\Http\Requests\Api\V1\Chat;

use App\Services\Chat\AttachmentService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/conversations/{uuid}/group   (multipart)
 *
 * Rename a group, change its picture, or both. Any member may — see
 * GroupService::update for why that is not an admin-only act.
 */
class UpdateGroupRequest extends FormRequest
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
        return [
            'title' => ['sometimes', 'nullable', 'string', 'min:1', 'max:80'],

            'avatar' => [
                'sometimes',
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp,heic',
                'max:'.AttachmentService::MAX_IMAGE_KB,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.max' => 'A group name can be 80 characters.',
            'avatar.image' => 'That does not look like an image.',
        ];
    }

    public function title(): ?string
    {
        $title = $this->input('title');

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }
}
