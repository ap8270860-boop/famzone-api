<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:120'],

            'phone_country_code' => ['required', 'string', 'max:8', 'regex:/^\+\d{1,4}$/'],
            'phone_number' => [
                'required', 'string', 'min:6', 'max:20', 'regex:/^\d+$/',
                // Unique against the pair, not the number alone — the same
                // digits are a different person in a different country.
                Rule::unique('users', 'phone_number')
                    ->where('phone_country_code', $this->input('phone_country_code'))
                    ->whereNull('deleted_at'),
            ],

            'email' => [
                'nullable', 'email:rfc', 'max:255',
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],

            'user_type' => ['required', Rule::in([
                User::TYPE_ADULT, User::TYPE_KID, User::TYPE_SENIOR,
            ])],
            'education_stage' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('user_type') === User::TYPE_KID),
                Rule::in([User::STAGE_SCHOOL, User::STAGE_COLLEGE]),
            ],

            'referral_code' => [
                'nullable', 'string', 'size:8',
                Rule::exists('users', 'referral_code'),
            ],

            'device_token' => ['nullable', 'string', 'max:4096'],
            'device_type' => ['nullable', Rule::in([
                User::DEVICE_ANDROID, User::DEVICE_IOS, User::DEVICE_WEB,
            ])],
            'device_id' => ['nullable', 'string', 'max:191'],
            'device_model' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // education_stage only means something for a child account.
            if ($this->input('user_type') !== User::TYPE_KID
                && filled($this->input('education_stage'))) {
                $v->errors()->add(
                    'education_stage',
                    'Education stage only applies to a child account.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone_number.unique' => 'That number is already registered. Try signing in.',
            'phone_number.regex' => 'Enter digits only, without spaces or symbols.',
            'phone_country_code.regex' => 'Country code should look like +91.',
            'referral_code.exists' => 'That referral code does not exist.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone_number' => preg_replace('/\D/', '', (string) $this->input('phone_number')),
            'referral_code' => filled($this->input('referral_code'))
                ? strtoupper(trim((string) $this->input('referral_code')))
                : null,
        ]);
    }
}
