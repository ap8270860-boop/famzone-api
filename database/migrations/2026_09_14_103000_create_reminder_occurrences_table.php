<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What actually happened on a given day.
 *
 * The table that makes a score possible, and the one most likely to be built
 * wrong. The tempting design is to materialise every future occurrence when a
 * reminder is saved — and it is unbounded: one daily reminder is 365 rows a
 * year, ten of them is a row every hour forever, and editing the time means
 * rewriting thousands of rows that have not happened yet.
 *
 * So the rule is:
 *
 *   **the future is computed, the past is stored.**
 *
 * Today and tomorrow are expanded from the recurrence rule on the fly — cheap,
 * always current, and free to change the instant somebody edits the schedule.
 * A row appears here only when an occurrence *settles*: the person marked it
 * done, snoozed it, skipped it, or the nightly close-out found the day gone
 * with nothing recorded and wrote it off as missed.
 *
 * That gives the property that matters for a medicine log: **history is
 * immutable**. Change your 8am dose to 9am tomorrow and yesterday still says
 * 8am, because yesterday is a row and not a calculation. Recomputing the past
 * from the current rule — which is what a purely-computed design does — would
 * quietly rewrite the record every time somebody adjusted a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_occurrences', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('reminder_id')
                ->constrained('reminders')
                ->cascadeOnDelete();

            /*
             | Whose adherence this is — the assignee, not the creator.
             |
             | Denormalised from the reminder deliberately. The score query is
             | "everything due to me between two dates", and routing it through
             | a join to reminders on every calendar scroll is a join that
             | buys nothing: an occurrence's owner cannot change, because
             | reassigning a reminder does not retrospectively make somebody
             | else responsible for last Tuesday.
             */
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             | The local calendar day this belonged to, in the reminder's own
             | timezone — resolved once, here, exactly as safety_check_ins does
             | it and for the same reason. The calendar groups by this, and
             | deriving it at read time would make a UTC-evening dose show up
             | on tomorrow's square.
             */
            $table->date('due_on');

            // The instant it was due, in UTC. Kept alongside due_on because
            // "was it taken on time" is a question about the clock, not the
            // calendar.
            $table->timestamp('due_at');

            // done | missed | snoozed | skipped
            $table->string('status', 16);

            $table->timestamp('completed_at')->nullable();

            /*
             | Where a snooze pushed it to.
             |
             | A snoozed row is not settled — it is the same occurrence waiting
             | for a second answer, which is why snooze is a status here rather
             | than a new row. Two rows would double the denominator of the
             | score and make a snoozed dose count as two.
             */
            $table->timestamp('snoozed_until')->nullable();

            $table->timestamps();

            /*
             | One row per occurrence, enforced.
             |
             | The nightly close-out and a late tap on the notification can
             | reach the same occurrence at the same moment; this makes the
             | loser fail loudly instead of quietly recording the dose twice.
             | due_at and not due_on, because a medicine can be due three times
             | in one day.
             */
            $table->unique(['reminder_id', 'due_at']);

            // The calendar, and the score behind it.
            $table->index(['user_id', 'due_on']);

            // The close-out sweep: yesterday's unsettled rows.
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_occurrences');
    }
};
