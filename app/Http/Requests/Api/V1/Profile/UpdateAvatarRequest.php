<?php

namespace App\Http\Requests\Api\V1\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `image` checks the real file signature, not the extension, so a
            // renamed script cannot get through.
            'avatar' => [
                'required', 'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',              // 5 MB
                'dimensions:min_width=100,min_height=100',
            ],

            // Which of the two pictures this replaces.
            'slot' => ['nullable', Rule::in(['primary', 'alternate'])],
        ];
    }

    public function slot(): string
    {
        return $this->input('slot', 'primary');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.image' => 'That file is not an image.',
            'avatar.max' => 'Images must be under 5 MB.',
            'avatar.dimensions' => 'That image is too small — 100x100 at minimum.',
        ];
    }
}
