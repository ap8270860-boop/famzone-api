<?php

namespace App\Http\Requests\Api\V1\Location;

use App\Services\Location\LocationService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A buffer of fixes from one phone.
 *
 * Validation here is only about shape — is this a number, is it a plausible
 * coordinate at all. Whether a fix is *believable* is a different question
 * with a different answer, and LocationService::ping owns it: a 3 km-accurate
 * tower fix is perfectly valid input and still must not be drawn on a map.
 *
 * The array is capped rather than rejected when long, because the client that
 * sends 200 points is a client coming back from an hour in a dead spot, and
 * failing its whole flush would strand the trail permanently.
 */
class PingLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        /*
         | Accept a bare fix as well as a buffer.
         |
         | The common case by a wide margin is one point, and making every
         | caller wrap it in an array is the kind of ceremony that gets
         | half-remembered and shipped wrong.
         */
        if ($this->has('latitude') && ! $this->has('fixes')) {
            $this->merge(['fixes' => [$this->only([
                'latitude', 'longitude', 'accuracy', 'speed', 'heading',
                'battery_level', 'moving', 'recorded_at',
            ])]]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fixes' => ['required', 'array', 'min:1', 'max:'.LocationService::MAX_FIXES_PER_PING],

            'fixes.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'fixes.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'fixes.*.accuracy' => ['nullable', 'numeric', 'min:0', 'max:65535'],
            'fixes.*.speed' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'fixes.*.heading' => ['nullable', 'numeric', 'between:0,360'],
            'fixes.*.battery_level' => ['nullable', 'integer', 'between:0,100'],
            'fixes.*.moving' => ['nullable', 'boolean'],
            'fixes.*.recorded_at' => ['nullable', 'date'],
        ];
    }
}
