<?php

namespace App\Http\Requests\Api\V1\Location;

use App\Models\FamilyPlace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A named circle, on its way in.
 *
 * The radius bounds are the interesting part and they are not arbitrary.
 *
 * The floor exists because consumer GPS cannot reliably tell inside from
 * outside a circle smaller than its own error. A 30 m geofence does not
 * produce a precise result — it produces a stream of arrivals and departures
 * from somebody sitting still in their kitchen, and then the user turns
 * notifications off and never turns them back on.
 *
 * The ceiling exists because past a couple of kilometres the thing being
 * described is not a place, it is a district, and "At Home" would be true
 * across half a city.
 */
class SavePlaceRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:60'],

            /*
             | Validated against the known list, unlike the column, which
             | accepts anything.
             |
             | The difference is deliberate: the column is loose so a future
             | server can add a kind without a migration, and the request is
             | strict so a *client* cannot invent one. An unknown kind from a
             | phone is a typo or a stale build, and either way it would draw
             | a blank icon on every other family member's map.
             */
            'kind' => ['nullable', Rule::in(FamilyPlace::KINDS)],

            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],

            'radius_m' => [
                'required',
                'integer',
                'min:'.FamilyPlace::MIN_RADIUS_M,
                'max:'.FamilyPlace::MAX_RADIUS_M,
            ],

            'notify_on_arrive' => ['nullable', 'boolean'],
            'notify_on_leave' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'radius_m.min' => 'A place needs a radius of at least '
                .FamilyPlace::MIN_RADIUS_M.' metres — below that, GPS cannot '
                .'tell reliably whether somebody is inside it.',
            'radius_m.max' => 'A place can be at most '
                .(FamilyPlace::MAX_RADIUS_M / 1000).' km across its radius.',
        ];
    }
}
