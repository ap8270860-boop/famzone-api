<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delete for me.
 *
 * A row per person per message, not a column on the message: "delete for me"
 * is one reader's opinion about a message that other readers still have. The
 * message itself is untouched, which is the whole difference between this and
 * delete for everyone — the other person's copy is none of your business.
 *
 * The same shape as message_stars, and for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_hides', function (Blueprint $table) {
            $table->id();

            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamp('created_at')->nullable();

            // Hiding twice is the same as hiding once.
            $table->unique(['message_id', 'user_id']);

            /*
             | The filter every history page runs.
             |
             | Leading on user_id because the question is always "what has
             | this one person hidden", never "who has hidden this message".
             */
            $table->index(['user_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_hides');
    }
};
