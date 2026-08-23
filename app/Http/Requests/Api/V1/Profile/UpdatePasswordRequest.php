<?php

namespace App\Http\Requests\Api\V1\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
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
            // Required only if one is already set. Phone-plus-OTP accounts
            // have no password until they choose to add one.
            'current_password' => [
                filled($this->user()->password) ? 'required' : 'nullable',
                'string',
            ],
            'password' => [
                'required', 'string', 'confirmed',
                Password::min(8)->letters()->numbers()->uncompromised(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.confirmed' => 'The two passwords do not match.',
            'current_password.required' => 'Enter your current password.',
        ];
    }
}
