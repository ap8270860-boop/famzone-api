<?php

namespace App\Http\Requests\Api\V1\Chat;

use App\Services\Chat\ReactionService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/messages/{uuid}/react
 *
 * `emoji: null` removes whatever the caller had on it, so one endpoint covers
 * add, change and remove. A separate DELETE would be a second route for the
 * same one-row write.
 */
class ReactRequest extends FormRequest
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
            'emoji' => [
                'present',
                'nullable',
                'string',
                'max:'.ReactionService::MAX_LENGTH,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'emoji.present' => 'Send an emoji, or null to remove your reaction.',
        ];
    }

    public function emoji(): ?string
    {
        $emoji = $this->input('emoji');

        if (! is_string($emoji)) {
            return null;
        }

        $emoji = trim($emoji);

        return $emoji === '' ? null : $emoji;
    }
}
