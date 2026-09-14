<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The twelve kinds of reminder, as the picker shows them.
 *
 * Seeded rather than hard-coded in the app, which is the whole reason this
 * table exists. A category is a name, a glyph, a colour pair and a mood — none
 * of which is a *decision* the client should own. Adding a thirteenth, or
 * renaming one for a market, or turning one off, is then a seed edit and a
 * deploy rather than a new build sitting in review for a week.
 *
 * Nothing here belongs to a user. This is a catalogue: every account reads the
 * same rows, and a person's own reminders point back at them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            /*
             | The stable name the client keys its artwork off.
             |
             | `key` and not the display name: "Morning" may become
             | "Good morning" or be translated, and neither should change which
             | animation plays. Unique, and never edited once shipped.
             */
            $table->string('key', 32)->unique();

            $table->string('name', 64);

            /*
             | A material icon name, resolved through a lookup in the client.
             |
             | Deliberately a name rather than a codepoint. Shipping raw
             | codepoints from the server means tree-shaking strips the glyph
             | from the font at build time and the app draws empty boxes — the
             | classic and very confusing failure of dynamic icons in Flutter.
             */
            $table->string('icon', 48)->default('alarm');

            /*
             | The "vibe": which animated treatment this category wears.
             |
             | Separate from the colours so that two categories can share a
             | motion (both night-ish ones drift) while looking nothing alike.
             */
            $table->string('vibe', 32)->default('plain');

            // The gradient, as two hex strings. Held here rather than in the
            // app so a category added by seed arrives fully dressed.
            $table->string('colour_from', 9)->default('#2F7BF0');
            $table->string('colour_to', 9)->default('#12A3E7');

            $table->string('tagline', 120)->nullable();

            /*
             | Whether this is the "make your own" tile.
             |
             | Exactly one row carries it. Flagged rather than matched on the
             | key so the picker can put it last and style it differently
             | without knowing the word "custom".
             */
            $table->boolean('is_custom')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);

            // Retiring a category must not break the reminders already
            // pointing at it, so they are hidden rather than deleted.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_categories');
    }
};
