<?php

namespace App\Http\Requests\Api\V1\Location;

use App\Models\LocationShare;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Starting a share.
 *
 * The opening fix is optional throughout: a cold GPS lock can take fifteen
 * seconds, and the share should begin the moment it is asked for rather than
 * when the satellites agree.
 */
class ShareLocationRequest extends FormRequest
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
            'audience' => ['required', Rule::in([
                LocationShare::AUDIENCE_CONVERSATION,
                LocationShare::AUDIENCE_FAMILY,
            ])],

            'conversation_id' => [
                Rule::requiredIf(
                    fn () => $this->input('audience') === LocationShare::AUDIENCE_CONVERSATION,
                ),
                'uuid',
            ],

            /*
             | Only the three the picker offers.
             |
             | A free integer would let a client ask for a week, and an
             | eight-hour ceiling that the server does not enforce is not a
             | ceiling.
             */
            'minutes' => [
                Rule::requiredIf(
                    fn () => $this->input('audience') === LocationShare::AUDIENCE_CONVERSATION,
                ),
                'integer',
                Rule::in(LocationShare::DURATIONS),
            ],

            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:65535'],
            'speed' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'battery_level' => ['nullable', 'integer', 'between:0,100'],
            'moving' => ['nullable', 'boolean'],
            'recorded_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'minutes.in' => 'Pick 15 minutes, 1 hour or 8 hours.',
            'conversation_id.required' => 'A chat share needs a conversation.',
        ];
    }
}
