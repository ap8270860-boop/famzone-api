<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
            'phone_country_code' => ['required', 'string', 'max:8', 'regex:/^\+\d{1,4}$/'],
            'phone_number' => ['required', 'string', 'min:6', 'max:20', 'regex:/^\d+$/'],
            'password' => ['required', 'string', 'min:8', 'max:128'],

            'device_token' => ['nullable', 'string', 'max:4096'],
            'device_type' => ['nullable', 'string', 'max:16'],
            'device_id' => ['nullable', 'string', 'max:191'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone_number' => preg_replace('/\D/', '', (string) $this->input('phone_number')),
        ]);
    }
}
