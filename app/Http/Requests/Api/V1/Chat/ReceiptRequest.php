<?php

namespace App\Http\Requests\Api\V1\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/conversations/{uuid}/read
 * POST /api/v1/conversations/{uuid}/delivered
 */
class ReceiptRequest extends FormRequest
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
            // The message the watermark should move to. Existence is checked
            // against this conversation in the service, not here — a message
            // that exists but belongs to somebody else's thread is a
            // different failure than one that does not exist at all.
            'message_id' => ['required', 'uuid'],
        ];
    }

    public function messageUuid(): string
    {
        return (string) $this->input('message_id');
    }
}
