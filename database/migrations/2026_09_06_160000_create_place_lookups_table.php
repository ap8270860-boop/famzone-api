<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Answers already bought from Google.
 *
 * Every row here is a Places API call that does not have to happen again, and
 * that matters more than it looks. Nearby Search bills on the Pro tier and
 * phone numbers on Enterprise — the most expensive thing in the whole system
 * — and the answers barely change: the hospitals within a kilometre of a
 * given street corner are the same today as they were last week, for every
 * user who stands there.
 *
 * So searches are cached by *area* rather than by exact position. Coordinates
 * are rounded to roughly a kilometre before they become a key, which means
 * one paid call serves everybody in that neighbourhood for a week instead of
 * one call per person per tap.
 *
 * Also the reason the Google key never ships in the app: a client calling
 * Google directly cannot be cached, cannot be rate-limited, and needs an
 * unrestricted key inside the APK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_lookups', function (Blueprint $table) {
            $table->id();

            /*
             | What was asked, as one string.
             |
             | "nearby:hospital:28.61:77.21" or "contact:ChIJ...". Unique, so
             | two simultaneous requests for the same area cannot both write
             | a row — the second gets a duplicate-key error and re-reads,
             | which is the correct outcome.
             */
            $table->string('cache_key', 191)->unique();

            $table->json('payload');

            /*
             | Places are stable, so these live for days rather than minutes.
             | Set per kind in PlacesService — a hospital's phone number
             | outlives the list it appeared in.
             */
            $table->timestamp('expires_at');

            $table->timestamps();

            /** For the sweep that clears expired rows. */
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_lookups');
    }
};
