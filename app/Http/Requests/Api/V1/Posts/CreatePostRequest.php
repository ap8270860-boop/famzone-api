<?php

namespace App\Http\Requests\Api\V1\Posts;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Publish a photo post.
 *
 * multipart/form-data — the image is a file, so JSON is not an option.
 */
class CreatePostRequest extends FormRequest
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
            // `image` validates the real file signature rather than the
            // extension, so a renamed script cannot get through.
            'image' => [
                'required', 'image',
                'mimes:jpg,jpeg,png,webp',
                'max:8192',                               // 8 MB
                'dimensions:min_width=320,min_height=320',
            ],

            'caption' => ['nullable', 'string', 'max:'.Post::CAPTION_MAX],

            // Public ids of people to tag. The service checks the
            // relationship — validation here only checks the shape.
            'tagged' => ['nullable', 'array', 'max:'.Post::MAX_TAGS],
            'tagged.*' => ['string', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => 'Choose a photo to post.',
            'image.image' => 'That file is not an image.',
            'image.max' => 'Photos must be under 8 MB.',
            'image.dimensions' => 'That photo is too small — 320x320 at minimum.',
            'caption.max' => 'Captions are limited to '.Post::CAPTION_MAX.' characters.',
            'tagged.max' => 'You can tag up to '.Post::MAX_TAGS.' people.',
        ];
    }

    /**
     * @return list<string>
     */
    public function taggedUuids(): array
    {
        $tagged = $this->input('tagged', []);

        return is_array($tagged) ? array_values(array_filter($tagged)) : [];
    }
}
