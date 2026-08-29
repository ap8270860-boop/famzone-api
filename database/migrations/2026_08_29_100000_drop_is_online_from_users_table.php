<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop users.is_online.
 *
 * The column was created with the original SFamily fields and nothing ever
 * wrote to it — every row has held `false` since the day it was added.
 *
 * It is not being replaced, because a stored online flag cannot be kept
 * honest. Nothing fires when a phone goes flat, an app is force-killed, or a
 * train enters a tunnel, so the column would need a background sweep to flip
 * people offline and would be lying in the meantime. Online is derived
 * instead, in PresenceService:
 *
 *     last_seen_at > now() - 75 seconds
 *
 * which is correct at the instant it is read and has nothing to maintain.
 *
 * Removed now rather than left in place: a column that looks authoritative
 * and is permanently wrong is worse than no column at all. Sooner or later
 * somebody writes `WHERE is_online = 1`, gets nothing back, and loses an
 * afternoon — or does not notice, and ships a report built on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_online');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Restored where it was, so a rollback leaves the table in the
            // shape the earlier migration produced rather than a similar one.
            $table->boolean('is_online')->default(false)->after('last_seen_at');
        });
    }
};
