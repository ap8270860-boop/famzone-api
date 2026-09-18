<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where somebody is heading, while they are heading there.
 *
 * Columns on `users` rather than a `trips` table, and the reason is the read
 * pattern rather than laziness. Exactly one trip is live per person at a time,
 * and it is read on every single family position payload — the whole point is
 * that the marker can say "on the way to Dadar, 12 min". A join to a trips
 * table on every marker, for one row that is usually null, buys nothing.
 *
 * It also sits beside `last_location_*`, which is the same shape of data for
 * the same reason: the current state of one person, denormalised onto them.
 *
 * **This is not a history.** Nothing here is kept after the trip ends; the
 * columns are cleared. If journey history is ever wanted it belongs in
 * location_history, which already stores the track that was actually walked —
 * a far better record than a destination somebody typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // What the person called it: "Home", "J.J Gupta Hindi High School".
            $table->string('trip_label', 120)->nullable()->after('last_location_heading');

            $table->decimal('trip_lat', 10, 7)->nullable()->after('trip_label');
            $table->decimal('trip_lng', 10, 7)->nullable()->after('trip_lat');

            /*
             | When they are expected, not how long is left.
             |
             | Storing an instant rather than a duration means the countdown
             | stays honest without anybody re-saving it: a phone that loses
             | signal for ten minutes comes back with an ETA ten minutes
             | closer, which is the truth. A stored "12 minutes remaining"
             | would still say twelve.
             */
            $table->timestamp('trip_eta_at')->nullable()->after('trip_lng');

            $table->timestamp('trip_started_at')->nullable()->after('trip_eta_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'trip_label',
                'trip_lat',
                'trip_lng',
                'trip_eta_at',
                'trip_started_at',
            ]);
        });
    }
};
