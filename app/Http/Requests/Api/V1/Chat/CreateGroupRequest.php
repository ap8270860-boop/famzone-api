<?php

namespace App\Http\Requests\Api\V1\Chat;

use App\Services\Chat\AttachmentService;
use App\Services\Chat\GroupService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/conversations/group   (multipart)
 *
 * The name, the people and the picture arrive together. A two-request create
 * would leave a group with no photo every time the second one failed, and
 * there is no good moment to retry it.
 */
class CreateGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Multipart carries everything as a string, so member_ids arrives either
     * as repeated fields or as one JSON array. Both are normalised here so
     * the rules below see a list.
     */
    protected function prepareForValidation(): void
    {
        $ids = $this->input('member_ids');

        if (is_string($ids)) {
            $decoded = json_decode($ids, true);

            $this->merge(['member_ids' => is_array($decoded) ? $decoded : []]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             | A name is required.
             |
             | WhatsApp allows an unnamed group and then shows a list of
             | member names in its place, which reads fine at three people and
             | badly at twelve. Asking for one word up front is cheaper than
             | the row that has to guess what to say.
             */
            'title' => ['required', 'string', 'min:1', 'max:80'],

            'scope' => ['sometimes', Rule::in([
                GroupService::SCOPE_CONNECTIONS,
                GroupService::SCOPE_FAMILY,
            ])],

            'member_ids' => [
                'required',
                'array',
                'min:1',
                'max:'.(GroupService::MAX_MEMBERS - 1),
            ],
            'member_ids.*' => ['uuid'],

            // Checked by signature rather than extension, like every other
            // image the app accepts: this one is shown to everybody in the
            // group.
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
            'title.required' => 'Give the group a name.',
            'member_ids.required' => 'Choose at least one person.',
            'member_ids.max' => 'A group can hold '.GroupService::MAX_MEMBERS.' people.',
            'avatar.image' => 'That does not look like an image.',
        ];
    }

    public function title(): string
    {
        return trim((string) $this->input('title'));
    }

    public function scope(): string
    {
        return $this->string('scope', GroupService::SCOPE_CONNECTIONS)->toString();
    }

    /**
     * @return array<int, string>
     */
    public function memberIds(): array
    {
        return array_values(array_filter(
            (array) $this->input('member_ids', []),
            static fn ($id) => is_string($id) && $id !== '',
        ));
    }
}
