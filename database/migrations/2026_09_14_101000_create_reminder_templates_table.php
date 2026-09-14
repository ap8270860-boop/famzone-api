<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The presets inside a category: Wake Up, Bath, Gym, Take medicine.
 *
 * These are *starting points*, not reminders. Tapping one opens the editor
 * pre-filled with a sensible time and repeat, and what gets saved is an
 * ordinary row in `reminders` that happens to remember which template it came
 * from. Nothing about a reminder changes if the template is later edited —
 * that link is for grouping and analytics, never for reading values back.
 *
 * The defaults are what make the feature feel considerate rather than
 * bureaucratic. "Wake Up" opening at 06:30 every weekday is a reminder
 * somebody confirms in one tap; "Wake Up" opening at 00:00 with no repeat is a
 * form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('reminder_category_id')
                ->constrained('reminder_categories')
                ->cascadeOnDelete();

            // Unique within its category, not globally: "Break" belongs to
            // Work and could one day belong to Study as well.
            $table->string('key', 48);

            $table->string('name', 64);
            $table->string('icon', 48)->default('alarm');

            /*
             | Where the editor opens. Null means "no opinion" — the editor
             | then starts at the next round half-hour, which is the right
             | default for a preset like "Doctor" that has no natural time.
             */
            $table->time('default_time')->nullable();

            // once | daily | weekly | monthly — see the reminders table for
            // the full description of each.
            $table->string('default_repeat', 16)->default('daily');

            /*
             | Which weekdays a weekly preset suggests, as a bitmask.
             |
             | Bit 0 is Monday through bit 6 Sunday. A mask rather than a JSON
             | array: it is fixed width, cannot arrive malformed, and "weekdays
             | only" is the literal constant 0b0011111.
             */
            $table->unsignedTinyInteger('default_weekday_mask')->default(0);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['reminder_category_id', 'key']);
            $table->index(['reminder_category_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_templates');
    }
};
