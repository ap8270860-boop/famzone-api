<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is inside which place, and since when.
 *
 * This table is the geofence's memory, and it exists because two things need
 * memory that a bare position cannot supply.
 *
 * ## Edges
 *
 * "Aarav arrived at school" is an *edge*, not a state. Detecting it means
 * knowing he was outside a moment ago, which means storing that he is inside
 * now. Without a row here, every ping from inside the school would announce
 * another arrival.
 *
 * ## Hysteresis
 *
 * A phone sitting on the boundary of a circle does not report a stable
 * distance — it reports a distance that wanders by tens of metres as
 * satellites come and go. Testing `distance <= radius` on each ping would
 * therefore produce a burst of arrivals and departures from somebody who has
 * not moved, which is the classic geofence failure and the reason people turn
 * these notifications off.
 *
 * So entering and leaving use different thresholds: you are in at the radius,
 * and you are not out until you are comfortably past it. Because the label on
 * the map is read from this row rather than recomputed from the position,
 * that hysteresis is inherited by the label for free — the map stops flipping
 * between "At Home" and "Travelling" for a phone on the doorstep.
 *
 * ## Confirmation
 *
 * `confirmed_at` is what stops a drive-past announcing an arrival. A row is
 * written the moment somebody crosses the radius, but it does not count as a
 * visit — and nothing is broadcast — until they are still inside a dwell
 * period later. Somebody who drives past the school at 40 km/h creates a row
 * and closes it again with nothing sent, which is correct: they did not
 * arrive anywhere.
 *
 * Closed rows are kept. "When did he get home yesterday" is the question this
 * whole feature exists to answer, and it is the one Phase C's history screen
 * is built on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_visits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('place_id')
                ->constrained('family_places')
                ->cascadeOnDelete();

            /** The person who is there — not the place's owner. */
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamp('entered_at');

            /**
             * When the dwell period was satisfied.
             *
             * Null means "inside, but not yet counted". The map draws no
             * label and nothing is broadcast until this is set.
             */
            $table->timestamp('confirmed_at')->nullable();

            /** Null while they are still there. */
            $table->timestamp('left_at')->nullable();

            $table->timestamps();

            /*
             | The hot query, run for every place on every accepted ping:
             | "is this person currently inside this place".
             */
            $table->index(['user_id', 'left_at'], 'visits_open');

            /** Phase C: one person's day, in order. */
            $table->index(['user_id', 'entered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_visits');
    }
};
