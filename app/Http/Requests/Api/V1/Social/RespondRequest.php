<?php

namespace App\Http\Requests\Api\V1\Social;

use App\Models\FamilyMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Accept or decline a follow request or a family invite.
 *
 * `accept` is required rather than defaulting to true. A malformed request
 * that quietly accepts on somebody's behalf is exactly the failure this
 * feature cannot afford — the whole point of the approval step is that it is
 * deliberate.
 */
class RespondRequest extends FormRequest
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
            'accept' => ['required', 'boolean'],

            // Only meaningful when accepting a family invite: how the accepting
            // side labels the person who invited them. "My daughter" and "my
            // mother" are the same link seen from two ends.
            'relation' => ['nullable', 'string', 'max:32', Rule::in(FamilyMember::RELATIONS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accept.required' => 'Say whether you accept or decline.',
        ];
    }

    public function accepts(): bool
    {
        return $this->boolean('accept');
    }
}
