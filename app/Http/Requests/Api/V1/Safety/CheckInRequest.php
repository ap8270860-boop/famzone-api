<?php

namespace App\Http\Requests\Api\V1\Safety;

use App\Models\SafetyCheckIn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Everything on a check-in is optional except the intent to make one.
 *
 * Location and battery are context, not requirements: refusing a check-in
 * because somebody denied location permission would turn a safety feature
 * into a nag. A bare POST with an empty body is a valid check-in.
 */
class CheckInRequest extends FormRequest
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
            'status' => ['nullable', Rule::in([
                SafetyCheckIn::STATUS_SAFE,
                SafetyCheckIn::STATUS_UNSAFE,
            ])],

            'note' => ['nullable', 'string', 'max:255'],

            // Sent together or not at all — half a coordinate is not a place.
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'location_accuracy' => ['nullable', 'integer', 'min:0', 'max:65535'],

            'battery_level' => ['nullable', 'integer', 'min:0', 'max:100'],

            'device_type' => ['nullable', 'string', 'max:16'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'latitude.required_with' => 'Send both coordinates or neither.',
            'longitude.required_with' => 'Send both coordinates or neither.',
            'note.max' => 'Keep the note under 255 characters.',
        ];
    }

    /**
     * The check-in always comes from a person tapping the button. A scheduled
     * or inferred check-in is written by the job that makes it, not by this
     * endpoint, so `source` is not something a client may claim.
     *
     * @return array<string, mixed>
     */
    public function checkInData(): array
    {
        return array_merge($this->validated(), [
            'source' => SafetyCheckIn::SOURCE_MANUAL,
        ]);
    }
}
