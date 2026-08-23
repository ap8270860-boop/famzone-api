<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalised check-in state on the user.
 *
 * The streak and last check-in could both be derived from safety_check_ins,
 * but the home screen asks for them on every open, and walking back through a
 * year of rows to count a streak is not something to do on a read path. They
 * are maintained inside the same transaction as the check-in itself, so they
 * cannot drift.
 *
 * Written as an ALTER rather than an edit to the users migration — that one
 * has already run everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // When the daily nudge fires, in the user's own timezone.
            // Nullable means "no reminder", not "use the default".
            $table->time('check_in_reminder_at')
                ->nullable()
                ->default('21:00:00')
                ->after('emergency_message');

            $table->timestamp('last_check_in_at')->nullable()->after('check_in_reminder_at');

            $table->unsignedInteger('check_in_streak')->default(0)->after('last_check_in_at');
            $table->unsignedInteger('longest_check_in_streak')->default(0)->after('check_in_streak');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'check_in_reminder_at',
                'last_check_in_at',
                'check_in_streak',
                'longest_check_in_streak',
            ]);
        });
    }
};
