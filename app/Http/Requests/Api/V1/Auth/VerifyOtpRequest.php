<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Models\OtpCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyOtpRequest extends FormRequest
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
            'code' => ['required', 'string', 'digits:'.config('otp.length')],
            'purpose' => ['nullable', Rule::in([
                OtpCode::PURPOSE_REGISTRATION,
                OtpCode::PURPOSE_LOGIN,
            ])],

            // Sent on verify so the device is registered for push the moment
            // the session begins, rather than a round trip later.
            'device_token' => ['nullable', 'string', 'max:4096'],
            'device_type' => ['nullable', 'string', 'max:16'],
            'device_id' => ['nullable', 'string', 'max:191'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone_number' => preg_replace('/\D/', '', (string) $this->input('phone_number')),
            'code' => preg_replace('/\D/', '', (string) $this->input('code')),
        ]);
    }

    public function purpose(): string
    {
        return $this->input('purpose', OtpCode::PURPOSE_REGISTRATION);
    }
}
