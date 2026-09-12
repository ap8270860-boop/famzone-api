<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The places a family cares about.
 *
 * Home, School, Office, the grandparents' house. A place is a point and a
 * radius, and it exists so the map can say "At School" instead of
 * "28.6139, 77.2090" — which is the difference between information and data.
 *
 * ## A place belongs to the person who made it
 *
 * There is no family *group* in this schema: family is a set of accepted
 * pairwise links, so "the family" is a different set for every member. That
 * leaves two possible meanings for a shared place and only one of them is
 * coherent.
 *
 * A place belongs to its creator, and the labels somebody sees are computed
 * against their *own* places. When a mother looks at the map and sees "Aarav
 * — At Home", the home in question is hers, which is exactly what she meant
 * by the question. Aarav's app, with no places set up, simply says
 * "Stationary".
 *
 * The alternative — pooling every family member's places and evaluating
 * against the union — produces two rows called "Home" the moment two people
 * in one family both set one up, and no way to say which was meant.
 *
 * The cost is that setup is per-person rather than once per household. That
 * is a real cost and the honest trade: it buys a model where no label is
 * ever ambiguous.
 *
 * ## Circles, not polygons
 *
 * A radius is a number a person can reason about and drag with one finger. A
 * polygon needs an editor, a winding rule, a point-in-polygon test, and it
 * answers a question — "exactly which side of the fence" — that a phone whose
 * accuracy is ±8 m on a good day cannot actually answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_places', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            /** Whose place this is. See the note above. */
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name', 60);

            /**
             * home | school | office | hospital | gym | park | shop | custom
             *
             * A string rather than an enum column: the list will grow, and a
             * migration to add "grandparents" to an enum is a deployment for
             * something that should be a constant. The client maps unknown
             * kinds to a neutral glyph.
             */
            $table->string('kind', 24)->default('custom');

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            /**
             * Metres.
             *
             * Floored at 80 in the request rules, not here. Below roughly
             * that, consumer GPS cannot tell inside from outside reliably,
             * and a 30 m geofence does not produce a precise result — it
             * produces a stream of arrivals and departures from somebody
             * sitting perfectly still in their kitchen.
             */
            $table->unsignedSmallInteger('radius_m')->default(150);

            $table->boolean('notify_on_arrive')->default(true);
            $table->boolean('notify_on_leave')->default(true);

            $table->timestamps();

            /*
             | Every geofence evaluation starts "which places should this
             | position be tested against", and the answer is always scoped by
             | owner. There is no spatial index here on purpose: a family has
             | a handful of places, and a bounding-box index earns its keep at
             | thousands of rows, not at eight.
             */
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_places');
    }
};
