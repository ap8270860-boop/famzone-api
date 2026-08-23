<?php

namespace App\Http\Requests\Api\V1\Social;

use App\Models\FamilyMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Invite an accepted follow into the family circle.
 */
class FamilyInviteRequest extends FormRequest
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
            // Optional: somebody can be family without a label yet, and asking
            // for one before the invite is even accepted is a step too many.
            'relation' => ['nullable', 'string', 'max:32', Rule::in(FamilyMember::RELATIONS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'relation.in' => 'Pick one of the suggested relationships.',
        ];
    }

    public function relation(): ?string
    {
        $value = $this->input('relation');

        return blank($value) ? null : (string) $value;
    }
}
