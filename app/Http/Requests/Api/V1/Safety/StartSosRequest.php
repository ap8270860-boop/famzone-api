<?php

namespace App\Http\Requests\Api\V1\Safety;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pressing the button.
 *
 * Everything is optional, deliberately — including the position. A validation
 * error is an acceptable answer to "you typed your email wrong" and an
 * unacceptable one to "I am in trouble": the alert must be recordable with
 * nothing but a bearer token, from a phone that has no fix, no battery
 * reading and nothing typed in the box.
 *
 * Category is optional for the same reason. The alarm comes first; working
 * out whether you need police or an ambulance comes seconds later, through
 * the update endpoint.
 */
class StartSosRequest extends FormRequest
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
            /*
             | Not constrained to the directory's keys.
             |
             | The catalogue lives in EmergencyDirectory and is expected to
             | gain entries; a rule that had to be kept in step with it would
             | eventually reject a category the server itself had just sent
             | to the client. Length is the only thing worth enforcing.
             */
            'category' => ['nullable', 'string', 'max:32'],

            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:65535'],
            'battery_level' => ['nullable', 'integer', 'between:0,100'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
