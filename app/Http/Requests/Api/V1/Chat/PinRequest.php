<?php

namespace App\Http\Requests\Api\V1\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/conversations/{uuid}/pin
 *
 * `message_id: null` clears the pin, so one endpoint covers pin, replace and
 * unpin — there is only ever one pinned message, so those are all the same
 * single-column write.
 */
class PinRequest extends FormRequest
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
            'message_id' => ['present', 'nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message_id.present' => 'Send a message id, or null to unpin.',
        ];
    }

    public function messageUuid(): ?string
    {
        $id = $this->input('message_id');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
