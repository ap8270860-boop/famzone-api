<?php

namespace App\Http\Requests\Api\V1\Social;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Block a person.
 *
 * The reason is optional and never shown to the blocked account — it exists
 * so a later report flow has something to attach to.
 */
class BlockRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:64', Rule::in([
                'spam', 'harassment', 'impersonation', 'inappropriate', 'other',
            ])],
        ];
    }

    public function reason(): ?string
    {
        $value = $this->input('reason');

        return blank($value) ? null : (string) $value;
    }
}
