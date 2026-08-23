<?php

namespace App\Http\Requests\Api\V1\Profile;

use Illuminate\Foundation\Http\FormRequest;

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

            // Length only, for now.
            //
            // The stricter set — letters, numbers and a Have I Been Pwned
            // breach check — rejected almost everything a user would actually
            // type, "Password@123" included, with no clear way for them to
            // know why. Worth reinstating before launch, alongside UI that
            // shows each requirement being met as they type.
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'Enter a new password.',
            'password.min' => 'Use at least 8 characters.',
            'password.max' => 'That password is too long.',
            'password.confirmed' => 'The two passwords do not match.',
            'current_password.required' => 'Enter your current password.',
        ];
    }
}
