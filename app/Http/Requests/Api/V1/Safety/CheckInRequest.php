<?php

namespace App\Http\Requests\Api\V1\Safety;

use App\Models\CheckInContact;
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

            /*
             | Who to tell, in order — sent only on the very first check-in,
             | where the client asks for the list and checks in with the same
             | tap.
             |
             | Present means "save this as my list and use it". Absent means
             | "use whatever I already have", which is the case on every later
             | day. An explicitly empty array is meaningful too: it clears the
             | list and makes the check-in private again.
             |
             | Membership is not validated here. Whether a uuid is accepted
             | family is a question about two tables and a status, which
             | belongs in the service that already has to ask it — and the
             | right answer to a stale id is to drop it, not to fail the
             | check-in.
             */
            'contacts' => ['sometimes', 'array', 'max:'.CheckInContact::MAX_CONTACTS],
            'contacts.*' => ['string', 'uuid'],
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
            'contacts.max' => 'You can choose up to '
                .CheckInContact::MAX_CONTACTS.' people to notify.',
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
        $data = $this->validated();

        // The contact list is not a column on the check-in. It is handled
        // alongside it and must not reach the row.
        unset($data['contacts']);

        return array_merge($data, [
            'source' => SafetyCheckIn::SOURCE_MANUAL,
        ]);
    }

    /**
     * The ordered list, or null when the client did not send one.
     *
     * Null and [] are different answers and the caller has to be able to tell
     * them apart: one means "leave my list alone", the other means "I want
     * nobody notified".
     *
     * @return list<string>|null
     */
    public function contactOrder(): ?array
    {
        if (! $this->has('contacts')) {
            return null;
        }

        /** @var array<int, mixed> $contacts */
        $contacts = $this->validated()['contacts'] ?? [];

        return array_values(array_filter(
            $contacts,
            static fn ($uuid) => is_string($uuid) && $uuid !== '',
        ));
    }
}
