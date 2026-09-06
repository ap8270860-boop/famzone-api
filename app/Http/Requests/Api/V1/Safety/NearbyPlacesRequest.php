<?php

namespace App\Http\Requests\Api\V1\Safety;

use App\Services\Safety\PlacesService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Searching for somewhere to go.
 *
 * Position is required here, unlike on the alert itself: "nearest hospital"
 * with no idea where you are is not a question that has an answer. The client
 * should keep the call buttons on screen and hide only the list when it has
 * no fix.
 */
class NearbyPlacesRequest extends FormRequest
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
             | Either a category from the directory, or free text — never
             | both, and never neither.
             |
             | A category maps to Google's place types on the server, which
             | keeps the client from ever naming one. Letting it pass raw
             | types through would mean the app deciding what gets billed.
             */
            'category' => ['required_without:query', 'nullable', 'string', 'max:32'],
            'query' => ['required_without:category', 'nullable', 'string', 'max:120'],

            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],

            'radius' => [
                'nullable',
                'integer',
                'min:500',
                'max:'.PlacesService::MAX_RADIUS,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'latitude.required' => 'We need your location to find places near you.',
            'longitude.required' => 'We need your location to find places near you.',
        ];
    }
}
