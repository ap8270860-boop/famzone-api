<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The breadcrumb trail.
 *
 * Every accepted fix lands here; the live position on the users row is only
 * the newest of them. Two things need the history rather than the latest
 * point: the line drawn behind a moving marker, and the honest answer to
 * "where was my daughter at four o'clock", which is the reason a family
 * safety app exists at all.
 *
 * This is the highest-write table in the system by a wide margin — a phone
 * moving through a city produces a row every few seconds — so it is
 * deliberately austere. No uuid, because a ping is never addressed on its
 * own; it is only ever read as a range. No updated_at, because a
 * measurement is never corrected. No foreign key to location_shares,
 * because the trail outlives the permission that created it and a cascade
 * there would erase history on every stop.
 *
 * It needs pruning. See the note on the index below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_pings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            $table->unsignedSmallInteger('accuracy')->nullable();
            $table->decimal('speed', 6, 2)->nullable();
            $table->decimal('heading', 5, 2)->nullable();

            $table->unsignedTinyInteger('battery_level')->nullable();
            $table->boolean('moving')->default(false);

            /*
             | When the phone took the reading, not when the server heard
             | about it.
             |
             | These differ by minutes whenever a client comes back from a
             | tunnel and flushes its buffer, and ordering a trail by
             | created_at instead would draw the line in the wrong order —
             | which looks exactly like the app teleporting somebody.
             */
            $table->timestamp('recorded_at');

            $table->timestamp('created_at')->nullable();

            /*
             | Every read is "this person, this time range", and the writes
             | are append-only in recorded_at order, so this index is also
             | the insertion order — no page splits under load.
             |
             | Pruning follows the same shape: deleting everything older than
             | ninety days is a range scan on the head of this index.
             */
            $table->index(['user_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_pings');
    }
};
