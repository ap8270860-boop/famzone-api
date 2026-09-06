<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of a location fix.
 *
 * The original schema already put `is_sharing_location`, `last_latitude`,
 * `last_longitude`, `last_location_at` and `battery_level` on users, along
 * with an index on (is_sharing_location, last_location_at) and the
 * `sharingLocation` scope that reads it. That was the right shape and this
 * keeps it: one row per person holding their last known position, rather
 * than a second table saying the same thing slightly differently.
 *
 * What was missing is everything that makes a fix usable rather than merely
 * present. Accuracy is what lets the server throw away a 2 km cell-tower
 * guess instead of drawing it on a map as fact. Speed and heading are what
 * let the client rotate and glide a marker between fixes rather than
 * teleporting it — the single largest difference between a map that feels
 * like Uber's and one that feels broken.
 *
 * Messages get a coordinate pair for the same reason a message gets an
 * attachment: a dropped pin is a message, and putting its position anywhere
 * else would mean a message whose content lives in another table with its
 * own lifetime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             | Metres. Google reports this as a 68% confidence radius, so a
             | value of 12 means "somewhere in a 12 m circle", not "12 m
             | wrong". Small integer because anything past a kilometre is
             | equally useless and gets discarded rather than stored.
             */
            $table->unsignedSmallInteger('last_location_accuracy')
                ->nullable()
                ->after('last_location_at');

            /*
             | Metres per second, as both platforms report it. Kept in the
             | source unit: converting to km/h here would mean every consumer
             | has to know which unit this particular column chose.
             */
            $table->decimal('last_location_speed', 6, 2)
                ->nullable()
                ->after('last_location_accuracy');

            /** Degrees clockwise from true north, 0–359.99. */
            $table->decimal('last_location_heading', 5, 2)
                ->nullable()
                ->after('last_location_speed');

            /*
             | Whether the phone believed it was moving when it sent this.
             |
             | Decided on the device, which is the only place with the
             | activity recogniser and the accelerometer. The server trusts
             | it for one purpose only — deciding how often to ask for the
             | next fix — so a lying client costs itself battery and nobody
             | else anything.
             */
            $table->boolean('last_location_moving')
                ->default(false)
                ->after('last_location_heading');
        });

        Schema::table('messages', function (Blueprint $table) {
            /*
             | 10,7 gives roughly 11 mm of resolution, which is far past what
             | any phone can actually determine. The extra digits cost two
             | bytes and remove a whole class of "why did the pin move" bug
             | reports caused by rounding on the way in.
             */
            $table->decimal('latitude', 10, 7)->nullable()->after('body');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'last_location_accuracy',
                'last_location_speed',
                'last_location_heading',
                'last_location_moving',
            ]);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
