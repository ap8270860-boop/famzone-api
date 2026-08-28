<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One person's membership of one thread.
 *
 * Everything per-person about a conversation lives here rather than on the
 * conversation itself: read state, unread count, mute, and whether the thread
 * has been accepted. Two people can be in wildly different states in the same
 * thread, which is exactly what a message request is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // pending | accepted | archived.
            //
            // pending is the Instagram message request: the thread exists and
            // the sender can write to it, but it sits in a Requests tab and
            // raises no notification until the recipient accepts.
            $table->string('state', 16)->default('accepted');

            /*
             | Delivery state, stored as two watermarks rather than a row per
             | message per reader.
             |
             | Reading is monotonic: having read message 40 means having read
             | everything below it. A message_reads table would store millions
             | of rows to record what one integer already implies, and marking
             | a 60-message backlog read would be 60 inserts instead of one
             | UPDATE. It is also why a whole run of ticks turns blue at once
             | rather than one at a time.
             |
             | Sequence numbers, not message ids — see conversations.last_seq.
             */
            $table->unsignedInteger('last_read_seq')->default(0);
            $table->unsignedInteger('last_delivered_seq')->default(0);

            // Recomputed exactly whenever the read watermark moves, rather
            // than decremented. Self-healing: a missed increment anywhere
            // corrects itself the next time the thread is opened.
            $table->unsignedInteger('unread_count')->default(0);

            $table->timestamp('muted_until')->nullable();

            $table->timestamp('joined_at')->nullable();

            // Set when someone leaves or declines a request. The row stays so
            // a later message can reopen the same thread instead of starting
            // a duplicate one.
            $table->timestamp('left_at')->nullable();

            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);

            // "Every thread this person is in, in this state" — the inbox and
            // the requests tab are both this query.
            $table->index(['user_id', 'state', 'left_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_participants');
    }
};
