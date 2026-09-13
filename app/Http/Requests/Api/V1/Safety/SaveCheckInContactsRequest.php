<?php

namespace App\Http\Requests\Api\V1\Safety;

use App\Models\CheckInContact;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The ordered list of people to tell, as the user arranged it.
 *
 * The order of the array *is* the order of notification. There is no position
 * field, and there should not be — a client that sends positions can send a
 * list with two threes in it, and then the server has to decide what that
 * means. An array has exactly one reading.
 */
class SaveCheckInContactsRequest extends FormRequest
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
            // Required, but allowed to be empty: sending [] is how somebody
            // turns the chain off and goes back to a private check-in.
            'contacts' => ['present', 'array', 'max:'.CheckInContact::MAX_CONTACTS],
            'contacts.*' => ['string', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contacts.present' => 'Send the list, even if it is empty.',
            'contacts.max' => 'You can choose up to '
                .CheckInContact::MAX_CONTACTS.' people to notify.',
        ];
    }

    /**
     * @return list<string>
     */
    public function contactOrder(): array
    {
        /** @var array<int, mixed> $contacts */
        $contacts = $this->validated()['contacts'] ?? [];

        return array_values(array_filter(
            $contacts,
            static fn ($uuid) => is_string($uuid) && $uuid !== '',
        ));
    }
}
