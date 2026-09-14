<?php

namespace App\Http\Requests\Api\V1\Reminders;

use App\Models\Reminder;
use App\Support\Reminders\Recurrence;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating or editing a reminder.
 *
 * The interesting validation is conditional: a weekly reminder needs days, a
 * monthly one needs a date, and the other two need neither. Expressed in
 * `after` rather than as `required_if` rules so the messages can say what is
 * actually wrong — "pick at least one day" beats "the weekday mask field is
 * required when repeat mode is weekly".
 */
class SaveReminderRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:48'],

            'category_id' => ['required', 'string', 'uuid'],
            'template_id' => ['nullable', 'string', 'uuid'],

            // Absent or my own id means a personal reminder; anybody else has
            // to be accepted family, which the service checks because it is a
            // question about two tables rather than about this payload.
            'assignee_id' => ['nullable', 'string', 'uuid'],

            'repeat_mode' => ['required', Rule::in(Recurrence::MODES)],

            // 24-hour wall clock. Seconds are accepted and thrown away.
            'time_of_day' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],

            // 7 bits: Monday through Sunday.
            'weekday_mask' => ['nullable', 'integer', 'min:0', 'max:127'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],

            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],

            'ringtone' => ['nullable', Rule::in(Reminder::RINGTONES)],
            'vibrate' => ['nullable', 'boolean'],

            // 0 turns snoozing off, which is the right setting for a reminder
            // nobody should be able to wave away by reflex.
            'snooze_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],

            'status' => ['nullable', Rule::in([
                Reminder::STATUS_ACTIVE,
                Reminder::STATUS_PAUSED,
            ])],

            // Medicine's dose and strength live here rather than in four
            // columns that are null on every other row.
            'meta' => ['nullable', 'array'],
            'meta.dose' => ['nullable', 'string', 'max:64'],
            'meta.strength' => ['nullable', 'string', 'max:64'],
            'meta.instructions' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $mode = $this->input('repeat_mode');

            if ($mode === Recurrence::WEEKLY && (int) $this->input('weekday_mask', 0) === 0) {
                $validator->errors()->add(
                    'weekday_mask',
                    'Pick at least one day of the week.',
                );
            }

            if ($mode === Recurrence::MONTHLY && $this->input('day_of_month') === null) {
                $validator->errors()->add(
                    'day_of_month',
                    'Pick which day of the month.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Give the reminder a name.',
            'time_of_day.regex' => 'The time should look like 07:30.',
            'ends_on.after_or_equal' => 'The end date cannot be before the start.',
            'ringtone.in' => 'That is not one of the available sounds.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reminderData(): array
    {
        $data = $this->validated();

        /*
         | Zero the field the chosen mode does not use.
         |
         | Switching a weekly reminder to daily has to clear its mask,
         | otherwise turning it back to weekly months later silently restores
         | days the person has long forgotten choosing. The rule should say
         | exactly what it means and nothing more.
         */
        $mode = $data['repeat_mode'] ?? Recurrence::DAILY;

        if ($mode !== Recurrence::WEEKLY) {
            $data['weekday_mask'] = 0;
        }

        if ($mode !== Recurrence::MONTHLY) {
            $data['day_of_month'] = null;
        }

        return $data;
    }
}
