<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One emoji per person per message.
 *
 * The unique index is the whole design. Reacting again with a different emoji
 * replaces the first rather than adding to it, and reacting with the same one
 * removes it — which is what people already expect, and it means a message
 * can never collect a wall of reactions from one enthusiastic person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             | The emoji itself, not an id into a table of our own.
             |
             | Emoji are a stable public standard, and a lookup table would
             | mean a migration every time somebody wants a new one. 32 bytes
             | covers a family with skin-tone and joiner sequences, which can
             | run to seven code points.
             |
             | utf8mb4 is required — the database's default collation is
             | already that, but it is the reason a plain utf8 column would
             | silently mangle anything above the basic plane.
             */
            $table->string('emoji', 32);

            $table->timestamps();

            // Also serves "every reaction on this message" — message_id is
            // its leading column, so a separate index would be written to on
            // every insert for nothing.
            $table->unique(['message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
    }
};
