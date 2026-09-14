<?php

namespace Database\Seeders;

use App\Models\ReminderCategory;
use App\Models\ReminderTemplate;
use App\Support\Reminders\Recurrence;
use Illuminate\Database\Seeder;

/**
 * The reminder catalogue.
 *
 * **Idempotent by key, on purpose.** This runs on every deploy, not once. New
 * presets appear, wording gets fixed, a colour gets adjusted — and none of
 * that may disturb the reminders people have already built on top of these
 * rows. So everything matches on `key` and updates in place: ids are stable,
 * foreign keys hold, and a category that has been renamed three times is still
 * the same row.
 *
 * Retirement is a flag, never a delete. Removing a row would cascade into
 * somebody's reminders; `is_active = false` takes it out of the picker and
 * leaves the reminders already using it working.
 *
 * The default times are the part worth arguing about, and they are chosen to
 * be *confirmed in one tap* rather than to be right for everybody. "Wake Up"
 * opening at 06:30 on weekdays is a reminder somebody adjusts by ten minutes;
 * "Wake Up" opening at midnight with no repeat is a form to fill in.
 */
class ReminderCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalogue() as $order => $category) {
            $row = ReminderCategory::updateOrCreate(
                ['key' => $category['key']],
                [
                    'name' => $category['name'],
                    'icon' => $category['icon'],
                    'vibe' => $category['vibe'],
                    'colour_from' => $category['from'],
                    'colour_to' => $category['to'],
                    'tagline' => $category['tagline'],
                    'is_custom' => $category['custom'] ?? false,
                    'sort_order' => ($order + 1) * 10,
                    'is_active' => true,
                ],
            );

            foreach ($category['templates'] as $index => $template) {
                ReminderTemplate::updateOrCreate(
                    [
                        'reminder_category_id' => $row->id,
                        'key' => $template['key'],
                    ],
                    [
                        'name' => $template['name'],
                        'icon' => $template['icon'],
                        'default_time' => $template['at'] ?? null,
                        'default_repeat' => $template['repeat'] ?? Recurrence::DAILY,
                        'default_weekday_mask' => $template['mask'] ?? 0,
                        'sort_order' => ($index + 1) * 10,
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalogue(): array
    {
        $weekdays = Recurrence::MASK_WEEKDAYS;

        return [
            [
                'key' => 'morning',
                'name' => 'Morning',
                'icon' => 'sunny',
                'vibe' => 'sunrise',
                'from' => '#FFB347',
                'to' => '#FF6B6B',
                'tagline' => 'Start the day right',
                'templates' => [
                    ['key' => 'wake_up', 'name' => 'Wake Up', 'icon' => 'alarm', 'at' => '06:30:00', 'repeat' => Recurrence::WEEKLY, 'mask' => $weekdays],
                    ['key' => 'drink_water', 'name' => 'Drink Water', 'icon' => 'water_drop', 'at' => '07:00:00'],
                    ['key' => 'bath', 'name' => 'Bath', 'icon' => 'shower', 'at' => '07:15:00'],
                    ['key' => 'breakfast', 'name' => 'Breakfast', 'icon' => 'breakfast', 'at' => '08:00:00'],
                    ['key' => 'stretch', 'name' => 'Stretch', 'icon' => 'self_improvement', 'at' => '06:45:00'],
                ],
            ],
            [
                'key' => 'study',
                'name' => 'Study',
                'icon' => 'school',
                'vibe' => 'focus',
                'from' => '#6C5CE7',
                'to' => '#A29BFE',
                'tagline' => 'Classes and homework',
                'templates' => [
                    ['key' => 'school', 'name' => 'School', 'icon' => 'school', 'at' => '07:30:00', 'repeat' => Recurrence::WEEKLY, 'mask' => $weekdays],
                    ['key' => 'college', 'name' => 'College', 'icon' => 'account_balance', 'at' => '09:00:00', 'repeat' => Recurrence::WEEKLY, 'mask' => $weekdays],
                    ['key' => 'homework', 'name' => 'Homework', 'icon' => 'edit_note', 'at' => '17:00:00', 'repeat' => Recurrence::WEEKLY, 'mask' => $weekdays],
                    ['key' => 'revision', 'name' => 'Revision', 'icon' => 'menu_book', 'at' => '19:30:00'],
                    ['key' => 'exam', 'name' => 'Exam', 'icon' => 'assignment', 'repeat' => Recurrence::ONCE],
                ],
            ],
            [
                'key' => 'work',
                'name' => 'Work',
                'icon' => 'work',
                'vibe' => 'steady',
                'from' => '#0EA5A5',
                'to' => '#2F7BF0',
                'tagline' => 'The working day',
                'templates' => [
                    ['key' => 'office', 'name' => 'Office', 'icon' => 'business', 'at' => '09:30:00', 'repeat' => Recurrence::WEEKLY, 'mask' => $weekdays],
                    ['key' => 'meeting', 'name' => 'Meeting', 'icon' => 'groups', 'repeat' => Recurrence::ONCE],
                    ['key' => 'break', 'name' => 'Break', 'icon' => 'coffee', 'at' => '16:00:00', 'repeat' => Recurrence::WEEKLY, 'mask' => $weekdays],
                    ['key' => 'standup', 'name' => 'Stand-up', 'icon' => 'record_voice_over', 'at' => '10:00:00', 'repeat' => Recurrence::WEEKLY, 'mask' => $weekdays],
                    ['key' => 'leave_office', 'name' => 'Leave Office', 'icon' => 'logout', 'at' => '18:30:00', 'repeat' => Recurrence::WEEKLY, 'mask' => $weekdays],
                ],
            ],
            [
                'key' => 'fitness',
                'name' => 'Fitness',
                'icon' => 'fitness',
                'vibe' => 'pulse',
                'from' => '#22C55E',
                'to' => '#A3E635',
                'tagline' => 'Move every day',
                'templates' => [
                    ['key' => 'gym', 'name' => 'Gym', 'icon' => 'fitness', 'at' => '18:00:00', 'repeat' => Recurrence::WEEKLY, 'mask' => $weekdays],
                    ['key' => 'running', 'name' => 'Running', 'icon' => 'run', 'at' => '06:00:00'],
                    ['key' => 'yoga', 'name' => 'Yoga', 'icon' => 'self_improvement', 'at' => '06:30:00'],
                    ['key' => 'walk', 'name' => 'Walk', 'icon' => 'walk', 'at' => '19:00:00'],
                    ['key' => 'meditation', 'name' => 'Meditation', 'icon' => 'spa', 'at' => '21:30:00'],
                    ['key' => 'water_goal', 'name' => 'Drink Water', 'icon' => 'water_drop', 'at' => '11:00:00'],
                ],
            ],
            [
                'key' => 'family',
                'name' => 'Family',
                'icon' => 'family',
                'vibe' => 'warmth',
                'from' => '#F472B6',
                'to' => '#FBBF24',
                'tagline' => 'The people who matter',
                'templates' => [
                    ['key' => 'family_time', 'name' => 'Family Time', 'icon' => 'family', 'at' => '20:00:00'],
                    ['key' => 'call_family', 'name' => 'Call Family', 'icon' => 'call', 'at' => '19:00:00', 'repeat' => Recurrence::WEEKLY, 'mask' => 0b1000000],
                    ['key' => 'kids', 'name' => 'Kids', 'icon' => 'child', 'at' => '16:30:00', 'repeat' => Recurrence::WEEKLY, 'mask' => $weekdays],
                    ['key' => 'school_pickup', 'name' => 'School Pickup', 'icon' => 'directions_bus', 'at' => '15:00:00', 'repeat' => Recurrence::WEEKLY, 'mask' => $weekdays],
                    ['key' => 'birthday', 'name' => 'Birthday', 'icon' => 'cake', 'repeat' => Recurrence::ONCE],
                ],
            ],
            [
                'key' => 'personal',
                'name' => 'Personal',
                'icon' => 'person',
                'vibe' => 'calm',
                'from' => '#29D3E8',
                'to' => '#2F7BF0',
                'tagline' => 'Errands and appointments',
                'templates' => [
                    ['key' => 'shopping', 'name' => 'Shopping', 'icon' => 'shopping', 'repeat' => Recurrence::ONCE],
                    ['key' => 'bank', 'name' => 'Bank', 'icon' => 'bank', 'repeat' => Recurrence::ONCE],
                    ['key' => 'doctor', 'name' => 'Doctor', 'icon' => 'stethoscope', 'repeat' => Recurrence::ONCE],
                    ['key' => 'haircut', 'name' => 'Haircut', 'icon' => 'content_cut', 'repeat' => Recurrence::ONCE],
                    ['key' => 'bills', 'name' => 'Pay Bills', 'icon' => 'receipt', 'at' => '10:00:00', 'repeat' => Recurrence::MONTHLY],
                    ['key' => 'rent', 'name' => 'Rent', 'icon' => 'home', 'at' => '09:00:00', 'repeat' => Recurrence::MONTHLY],
                ],
            ],
            [
                'key' => 'travel',
                'name' => 'Travel',
                'icon' => 'travel',
                'vibe' => 'drift',
                'from' => '#38BDF8',
                'to' => '#6366F1',
                'tagline' => 'Getting there and back',
                'templates' => [
                    ['key' => 'leave_home', 'name' => 'Leave Home', 'icon' => 'logout', 'repeat' => Recurrence::ONCE],
                    ['key' => 'reach_destination', 'name' => 'Reach Destination', 'icon' => 'flag', 'repeat' => Recurrence::ONCE],
                    ['key' => 'reach_home', 'name' => 'Reach Home', 'icon' => 'home', 'repeat' => Recurrence::ONCE],
                    ['key' => 'check_in_flight', 'name' => 'Flight Check-in', 'icon' => 'flight', 'repeat' => Recurrence::ONCE],
                    ['key' => 'pack', 'name' => 'Pack Bags', 'icon' => 'luggage', 'repeat' => Recurrence::ONCE],
                ],
            ],
            [
                'key' => 'night',
                'name' => 'Night',
                'icon' => 'night',
                'vibe' => 'stars',
                'from' => '#312E81',
                'to' => '#7C3AED',
                'tagline' => 'Wind down',
                'templates' => [
                    ['key' => 'read', 'name' => 'Read', 'icon' => 'menu_book', 'at' => '22:00:00'],
                    ['key' => 'sleep', 'name' => 'Sleep', 'icon' => 'bed', 'at' => '23:00:00'],
                    ['key' => 'wind_down', 'name' => 'Wind Down', 'icon' => 'spa', 'at' => '21:45:00'],
                    ['key' => 'plan_tomorrow', 'name' => 'Plan Tomorrow', 'icon' => 'edit_note', 'at' => '22:30:00'],
                ],
            ],
            [
                'key' => ReminderCategory::KEY_MEDICINE,
                'name' => 'Medicine',
                'icon' => 'medicine',
                /*
                 | Mint and aqua, not red.
                 |
                 | The instinct is to make medicine urgent. But this is the one
                 | category most likely to belong to somebody elderly, opened
                 | several times a day, every day — and a red alarm card three
                 | times a day reads as "something is wrong with you" rather
                 | than "here is your tablet". Calm is the correct register,
                 | and it is the same green the app already uses for safe.
                 */
                'vibe' => 'care',
                'from' => '#2BE07F',
                'to' => '#29D3E8',
                'tagline' => 'Never miss a dose',
                'templates' => [
                    ['key' => 'morning_dose', 'name' => 'Morning Dose', 'icon' => 'medicine', 'at' => '08:00:00'],
                    ['key' => 'afternoon_dose', 'name' => 'Afternoon Dose', 'icon' => 'medicine', 'at' => '14:00:00'],
                    ['key' => 'night_dose', 'name' => 'Night Dose', 'icon' => 'medicine', 'at' => '21:00:00'],
                    ['key' => 'before_food', 'name' => 'Before Food', 'icon' => 'restaurant', 'at' => '07:30:00'],
                    ['key' => 'after_food', 'name' => 'After Food', 'icon' => 'restaurant', 'at' => '13:30:00'],
                    ['key' => 'refill', 'name' => 'Refill Prescription', 'icon' => 'receipt', 'at' => '10:00:00', 'repeat' => Recurrence::MONTHLY],
                ],
            ],
            [
                'key' => 'enjoyment',
                'name' => 'Enjoyment',
                'icon' => 'celebration',
                'vibe' => 'confetti',
                'from' => '#D559A4',
                'to' => '#FB923C',
                'tagline' => 'Things to look forward to',
                'templates' => [
                    ['key' => 'movie', 'name' => 'Movie', 'icon' => 'movie', 'repeat' => Recurrence::ONCE],
                    ['key' => 'music', 'name' => 'Music', 'icon' => 'music', 'at' => '20:00:00'],
                    ['key' => 'outing', 'name' => 'Outing', 'icon' => 'celebration', 'repeat' => Recurrence::ONCE],
                    ['key' => 'hobby', 'name' => 'Hobby Time', 'icon' => 'palette', 'at' => '18:00:00', 'repeat' => Recurrence::WEEKLY, 'mask' => Recurrence::MASK_WEEKEND],
                ],
            ],
            [
                'key' => 'game',
                'name' => 'Game',
                'icon' => 'game',
                'vibe' => 'arcade',
                'from' => '#8B5CF6',
                'to' => '#22D3EE',
                'tagline' => 'Play, and stop playing',
                'templates' => [
                    ['key' => 'game_time', 'name' => 'Game Time', 'icon' => 'game', 'at' => '19:00:00'],
                    /*
                     | The one reminder here that says stop.
                     |
                     | In a family app the person setting a game reminder is
                     | often a parent, and the useful alarm is the end of the
                     | session rather than the start of it.
                     */
                    ['key' => 'stop_playing', 'name' => 'Stop Playing', 'icon' => 'timer_off', 'at' => '20:30:00'],
                    ['key' => 'match', 'name' => 'Match', 'icon' => 'sports_esports', 'repeat' => Recurrence::ONCE],
                ],
            ],
            [
                'key' => ReminderCategory::KEY_CUSTOM,
                'name' => 'Custom',
                'icon' => 'add',
                'vibe' => 'plain',
                'from' => '#64748B',
                'to' => '#94A3B8',
                'tagline' => 'Anything else',
                'custom' => true,
                // No presets on purpose: the whole point of this tile is to
                // go straight to an empty editor.
                'templates' => [],
            ],
        ];
    }
}
