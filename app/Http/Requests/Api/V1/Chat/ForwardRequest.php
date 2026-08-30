<?php

namespace App\Http\Requests\Api\V1\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/messages/{uuid}/forward
 */
class ForwardRequest extends FormRequest
{
    /** How many conversations one forward may reach. */
    public const MAX_TARGETS = 10;

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
            /*
             | Capped, deliberately.
             |
             | Forwarding is the mechanic every chain message rides on, and an
             | uncapped list turns one tap into a broadcast. Ten is more than
             | anybody forwards on purpose and far fewer than anybody forwards
             | on impulse.
             */
            'conversation_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_TARGETS],
            'conversation_ids.*' => ['uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'conversation_ids.required' => 'Choose at least one chat.',
            'conversation_ids.max' => 'You can forward to '.self::MAX_TARGETS.' chats at a time.',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function targets(): array
    {
        return array_values(array_filter(
            (array) $this->input('conversation_ids', []),
            static fn ($id) => is_string($id) && $id !== '',
        ));
    }
}
