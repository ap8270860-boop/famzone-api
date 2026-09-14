<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One reminder somebody set.
 *
 * Two people can be involved and they are not the same person. `user_id` is
 * who *set* it; `assignee_id` is whose phone rings. Usually they match. When
 * they do not — a daughter setting her father's blood-pressure reminder, a
 * parent setting homework — this becomes the more interesting half of the
 * feature, and the one that cannot be retrofitted onto a single-owner table
 * without rewriting every query that touches it.
 *
 * **The schedule is a rule, not a list of dates.** A daily 7am reminder is one
 * row, forever, not 365 rows a year. Occurrences are materialised only once
 * they are in the past and settled — see the occurrences table for why.
 *
 * **The ringing happens on the phone, not here.** Nothing in this table causes
 * a sound. The device reads these rows and registers real OS alarms, so a
 * reminder fires on a plane, in a tunnel, with the app force-closed and the
 * server switched off. The server owns the definition; the phone owns the
 * moment. Any other split gives you an alarm clock that needs four bars of
 * signal, which is not an alarm clock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // Who set it.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             | Whose phone rings.
             |
             | Never null — it is the creator's own id for an ordinary personal
             | reminder. A nullable column would mean every read had to write
             | `COALESCE(assignee_id, user_id)` and one of them would
             | eventually be forgotten.
             */
            $table->foreignId('assignee_id')->constrained('users')->cascadeOnDelete();

            /*
             | Where it came from in the catalogue.
             |
             | nullOnDelete on the template and not on the category: a
             | reminder must always know what *kind* of thing it is, because
             | that is its colour, its icon and its animation. Which preset it
             | started from is only a memory, and losing it costs nothing.
             */
            $table->foreignId('reminder_category_id')
                ->constrained('reminder_categories')
                ->cascadeOnDelete();

            $table->foreignId('reminder_template_id')
                ->nullable()
                ->constrained('reminder_templates')
                ->nullOnDelete();

            $table->string('title', 120);
            $table->string('note', 255)->nullable();

            /*
             | Overrides the category's icon when somebody picked their own.
             | Null means "use whatever the category says", which keeps a
             | reminder looking right if the category's artwork is redrawn.
             */
            $table->string('icon', 48)->nullable();

            /*
            |------------------------------------------------------------------
            | The schedule
            |------------------------------------------------------------------
            */

            // once | daily | weekly | monthly
            $table->string('repeat_mode', 16)->default('daily');

            /*
             | Wall-clock time, in [timezone] below. Not an instant.
             |
             | "Wake me at seven" means seven o'clock, and it keeps meaning
             | seven o'clock across a daylight-saving change. Storing an
             | instant would quietly move it an hour twice a year, which is
             | exactly the kind of bug nobody reports and everybody notices.
             */
            $table->time('time_of_day');

            /*
             | Which days, for `weekly`. Bit 0 = Monday ... bit 6 = Sunday.
             |
             | Zero for every other mode, and validated non-zero for weekly —
             | a weekly reminder on no days is a reminder that never fires and
             | looks broken rather than empty.
             */
            $table->unsignedTinyInteger('weekday_mask')->default(0);

            /*
             | Which day, for `monthly`. 1–31.
             |
             | 29, 30 and 31 are allowed and clamped to the last day of short
             | months at expansion time. The alternative — refusing the 31st —
             | means somebody paying rent on the 31st cannot say so.
             */
            $table->unsignedTinyInteger('day_of_month')->nullable();

            $table->date('starts_on');

            // Null runs forever. A `once` reminder sets this equal to
            // starts_on so "is it finished" is one comparison for every mode.
            $table->date('ends_on')->nullable();

            /*
             | The zone the wall-clock time is read in.
             |
             | Snapshotted from the assignee when the reminder is saved.
             | Carried explicitly rather than read live from the user, because
             | the server's expansion and the phone's alarms must agree about
             | what "07:00" meant, and a user row that changes on a flight
             | would silently move every past due time in the history.
             */
            $table->string('timezone', 64)->default('UTC');

            /*
            |------------------------------------------------------------------
            | How it rings
            |------------------------------------------------------------------
            */

            // A key from the bundled set, or 'default' for the phone's own
            // notification sound, or 'silent'. Never a file path: on iOS a
            // custom notification sound must be compiled into the app, so a
            // path from the server could not be played from a locked phone.
            $table->string('ringtone', 32)->default('default');

            $table->boolean('vibrate')->default(true);

            // 0 disables snoozing entirely, which is the right setting for a
            // reminder somebody must not be able to wave away by reflex.
            $table->unsignedSmallInteger('snooze_minutes')->default(10);

            /*
            |------------------------------------------------------------------
            | State
            |------------------------------------------------------------------
            */

            // active | paused | archived
            $table->string('status', 16)->default('active');

            /*
             | self | pending | accepted | declined
             |
             | 'self' rather than null when nobody was assigned. It makes
             | "should this ring" a single IN check instead of a null test
             | ORed with a status test, and it means a reminder can never sit
             | in a state the query forgot about.
             */
            $table->string('assignment_status', 16)->default('self');
            $table->timestamp('assignment_responded_at')->nullable();

            /*
             | Room for what only one category needs.
             |
             | Medicine carries a dose and a strength here rather than in four
             | columns that are null on every other row. A photo of the packet
             | gets its own column because it is a file with a lifecycle —
             | uploaded, served, deleted — and files do not belong in JSON.
             */
            $table->json('meta')->nullable();
            $table->string('photo_path')->nullable();

            $table->timestamps();

            /*
             | The query that runs on every app open: "what should this phone
             | be ringing for". Assignee first because that is the selective
             | half — a person has a handful of reminders and the table has
             | millions.
             */
            $table->index(['assignee_id', 'status', 'assignment_status']);

            // "What did I set for other people", which is a different screen.
            $table->index(['user_id', 'created_at']);

            // Waiting on somebody's answer, for the badge.
            $table->index(['assignee_id', 'assignment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
