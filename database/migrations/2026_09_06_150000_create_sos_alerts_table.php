<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One person raising an alarm.
 *
 * Append-only in spirit: an alert is a record that something happened, and
 * the only mutation it ever takes is being closed. It is never deleted, not
 * even by the person who raised it — "I pressed SOS at 11:40pm and cancelled
 * it two minutes later" is exactly the kind of thing that matters afterwards,
 * and an app that quietly erases its own alarms is not a safety app.
 *
 * The position is copied onto the row rather than joined from the user's live
 * location. That is deliberate duplication: where somebody was when they
 * raised the alarm is a permanent fact about the alert, and reading it from a
 * users row that has moved on since would answer a different question every
 * time it was asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sos_alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             | Which kind of help was wanted — police, ambulance and so on.
             |
             | Nullable because the alarm comes first and the category second:
             | somebody in trouble presses the button, and only then works out
             | who they need. Forcing a choice up front would put a menu
             | between a frightened person and their family.
             */
            $table->string('category', 32)->nullable();

            /** active | resolved | cancelled | false_alarm */
            $table->string('status', 16)->default('active');

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('accuracy')->nullable();

            /*
             | A human-readable place, when one is known.
             |
             | Filled opportunistically and never required. Reverse geocoding
             | is a separate billed Google call, so this stays null until
             | something has a reason to spend one — the coordinates are the
             | truth, and the text is a convenience.
             */
            $table->string('address')->nullable();

            $table->unsignedTinyInteger('battery_level')->nullable();

            /** What the person typed, if they had time to type anything. */
            $table->string('note', 500)->nullable();

            /**
             | The location share opened when the alarm was raised.
             |
             | Held so ending the alert can end the share it started, without
             | touching a share the person had running for their own reasons
             | beforehand.
             */
            $table->foreignId('location_share_id')
                ->nullable()
                ->constrained('location_shares')
                ->nullOnDelete();

            /** How many people were told. Recorded, because "did anyone
             |  actually get this" is the first question asked afterwards. */
            $table->unsignedSmallInteger('notified_count')->default(0);

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();

            /** The history screen, newest first, for one person. */
            $table->index(['user_id', 'started_at']);

            /** "Is anything live right now" — checked on every app resume. */
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sos_alerts');
    }
};
