<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One run of the notification chain, attached to one check-in.
 *
 * The header row. It exists so the question "has anybody confirmed they know
 * I'm safe today" is one indexed read rather than a scan across steps, and so
 * the thirty-minute timer has somewhere to live that the scheduler can find
 * without looking at every step in the system.
 *
 * `next_escalation_at` is the whole design.
 *
 * It is the only thing the sweep looks at: a single indexed column holding the
 * moment this run next needs attention, nulled the instant it does not. That
 * makes the minute-by-minute job a range scan over a handful of rows instead
 * of a walk through every escalation ever created — and it makes the timer
 * *recoverable*, which a delayed queue job is not. A server that was off for
 * two hours does not lose two hours of timers; it finds them all on the next
 * tick, overdue, and works through them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_in_escalations', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             | The check-in this chain is about.
             |
             | Unique: one chain per check-in, forever. A retried request must
             | not be able to start a second round of notifications to the same
             | family for the same day, and making that a database constraint
             | means the application cannot get it wrong under a race.
             */
            $table->foreignId('safety_check_in_id')
                ->unique()
                ->constrained('safety_check_ins')
                ->cascadeOnDelete();

            // pending | acknowledged | exhausted | cancelled
            $table->string('status', 16)->default('pending');

            /*
             | Which position currently holds the request. 0 before the first
             | notification has gone out, and left pointing at whoever last
             | held it once the run ends — so a finished chain can still say
             | how far it got.
             */
            $table->unsignedTinyInteger('current_step')->default(0);

            // How many people were in the list when this started. Snapshotted
            // so the progress bar's denominator cannot change mid-run.
            $table->unsignedTinyInteger('total_steps')->default(0);

            /*
             | The wait, in minutes, as it was when this run began.
             |
             | Carried per-run rather than read from a constant so that
             | changing the default later leaves chains already in flight
             | running on the terms they started under. Somebody who checked in
             | at ten to five should not find their escalation suddenly
             | overdue because a deploy shortened the window.
             */
            $table->unsignedSmallInteger('step_timeout_minutes')->default(30);

            // Who said "I know you're safe", and when.
            $table->foreignId('acknowledged_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            /*
             | When the current step runs out of patience. Null whenever there
             | is nothing to wait for — no step out yet, or the run is over.
             */
            $table->timestamp('next_escalation_at')->nullable();

            $table->timestamps();

            /*
             | The sweep's index.
             |
             | Status first, because it is the far more selective half: almost
             | every escalation in the table is finished, and a finished one
             | has a null next_escalation_at that MySQL would otherwise still
             | have to consider. Ordered this way the job touches only rows
             | that are genuinely waiting.
             */
            $table->index(['status', 'next_escalation_at']);

            // "Show me today's chain" from the home screen.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_in_escalations');
    }
};
