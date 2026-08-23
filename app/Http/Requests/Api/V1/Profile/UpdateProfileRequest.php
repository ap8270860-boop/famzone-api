<?php

namespace App\Http\Requests\Api\V1\Profile;

use App\Models\User;
use App\Services\Profile\UsernameChecker;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Every field is "sometimes": the app sends only what changed, so an
     * absent key means "leave it alone" rather than "clear it".
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],

            'username' => [
                'sometimes', 'nullable', 'string',
                'min:'.UsernameChecker::MIN, 'max:'.UsernameChecker::MAX,
                Rule::unique('users', 'username')->ignore($userId),
            ],

            'email' => [
                'sometimes', 'nullable', 'email:rfc', 'max:255',
                Rule::unique('users', 'email')->ignore($userId)->whereNull('deleted_at'),
            ],

            'about' => ['sometimes', 'nullable', 'string', 'max:160'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today', 'after:1900-01-01'],
            'gender' => ['sometimes', 'nullable', Rule::in([
                'male', 'female', 'other', 'prefer_not_to_say',
            ])],
            'blood_group' => ['sometimes', 'nullable', Rule::in([
                'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-',
            ])],

            'locale' => ['sometimes', 'string', 'max:10'],
            'timezone' => ['sometimes', 'string', 'max:64', 'timezone'],

            'emergency_message' => ['sometimes', 'nullable', 'string', 'max:255'],

            'user_type' => ['sometimes', Rule::in([
                User::TYPE_ADULT, User::TYPE_KID, User::TYPE_SENIOR,
            ])],
            'education_stage' => ['sometimes', 'nullable', Rule::in([
                User::STAGE_SCHOOL, User::STAGE_COLLEGE,
            ])],

            // Privacy
            'show_last_seen' => ['sometimes', 'boolean'],
            'show_online_status' => ['sometimes', 'boolean'],
            'show_read_receipts' => ['sometimes', 'boolean'],
            'allow_group_invites' => ['sometimes', 'boolean'],
            'is_private' => ['sometimes', 'boolean'],
            'use_alternate_avatar' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->filled('username')) {
                $checker = app(UsernameChecker::class);
                $username = $checker->normalise($this->string('username')->toString());

                if ($reason = $checker->reject($username)) {
                    $v->errors()->add('username', $reason[1]);
                }
            }

            // A child account must say which stage; nobody else may set one.
            $type = $this->input('user_type', $this->user()->user_type);

            if ($type === User::TYPE_KID
                && $this->has('user_type')
                && blank($this->input('education_stage', $this->user()->education_stage))) {
                $v->errors()->add('education_stage', 'Choose school or college.');
            }

            if ($type !== User::TYPE_KID && filled($this->input('education_stage'))) {
                $v->errors()->add(
                    'education_stage',
                    'Education stage only applies to a child account.',
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('username')) {
            $raw = $this->input('username');
            $this->merge([
                'username' => blank($raw)
                    ? null
                    : app(UsernameChecker::class)->normalise((string) $raw),
            ]);
        }
    }
}
