<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each watermark moved.
 *
 * The ticks themselves need no timestamps — two integers per participant
 * decide the state of every bubble, which is what makes a whole column turn
 * blue at once. But "Read · April 26, 10:57 AM" is a different question, and
 * two integers cannot answer it: they say how far somebody has got, not when
 * they got there.
 *
 * The obvious fix — a `last_read_at` column beside `last_read_seq` — is wrong
 * in a way that would ship and then be noticed: it holds the time of the most
 * recent read, so every message in the thread would report the same time.
 *
 * So: one row each time a watermark actually advances, recording how far it
 * moved and when. A person opening a thread with sixty unread messages writes
 * one row, not sixty. The read time for any message is then the first mark
 * that reached or passed its seq — exact, and still nothing per message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_marks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 'delivered' or 'read'. Both are recorded: a message can sit
            // delivered for hours before it is read, and the info screen
            // shows both lines.
            $table->string('kind', 12);

            // How far the watermark moved to.
            $table->unsignedBigInteger('seq');

            $table->timestamp('marked_at');

            /*
             | The lookup this table exists for: "the first mark of this kind,
             | by this person, in this thread, that reached seq N".
             |
             | Leading with the three equality columns and ending on the range
             | column means one index seek and no sort.
             */
            $table->index(['conversation_id', 'user_id', 'kind', 'seq'], 'receipt_marks_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_marks');
    }
};
