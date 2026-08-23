<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per user per day: "I am safe".
 *
 * The interesting column is check_in_date. "Daily" has to mean the user's own
 * calendar day, not UTC — somebody in Kolkata checking in at 11pm is on a
 * different UTC date, and deriving the local date at read time would make the
 * one-per-day rule impossible to enforce in the database. So the local date is
 * resolved once, on write, and carried here for the unique index to police.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_check_ins', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The user's local calendar day. Paired with user_id below to make
            // a second check-in on the same day a database error rather than a
            // race the application has to win.
            $table->date('check_in_date');
            $table->timestamp('checked_in_at');

            // safe | unsafe — room for a "check in but flag a problem" flow
            // without another migration.
            $table->string('status', 16)->default('safe');

            // manual | scheduled | auto | sos — how the check-in arrived.
            // Worth keeping: a check-in the user tapped means something
            // different from one a background job inferred.
            $table->string('source', 16)->default('manual');

            $table->string('note', 255)->nullable();

            // Context captured at the moment of check-in. All optional: a
            // check-in with no location is still a check-in, and refusing one
            // because location permission was denied would be hostile.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('location_accuracy')->nullable();
            $table->unsignedTinyInteger('battery_level')->nullable();

            $table->string('device_type', 16)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->ipAddress('ip_address')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'check_in_date']);

            // Drives the history endpoint and the streak walk-back.
            $table->index(['user_id', 'check_in_date', 'status']);
            $table->index('checked_in_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_check_ins');
    }
};
