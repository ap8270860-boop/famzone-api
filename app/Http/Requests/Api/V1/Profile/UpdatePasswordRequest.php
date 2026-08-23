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
                Password::min(8)
                    ->letters()
                    ->numbers()
                    // Checks the password against known breach corpora via
                    // Have I Been Pwned (k-anonymity — only a 5-character
                    // hash prefix leaves the server, never the password).
                    // Fails open if the service is unreachable.
                    ->uncompromised(),
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

            // Laravel's defaults for these are vague. Say what is actually
            // wrong so the user can fix it in one attempt.
            'password.min' => 'Use at least 8 characters.',
            'password.letters' => 'Include at least one letter.',
            'password.numbers' => 'Include at least one number.',
            'password.uncompromised' => 'That password has appeared in a known '
                .'data breach. Pick a different one.',
        ];
    }
}
