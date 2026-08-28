<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A thread between people.
 *
 * Direct only for now, but the table carries `type` from the start because
 * adding an enum value to what will become the second-largest table in the
 * database is an ALTER nobody wants to run later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // direct | group
            $table->string('type', 16)->default('direct');

            /*
             | sha256 of the two participant ids, smallest first.
             |
             | This is what makes "message this person" idempotent. Both people
             | tapping Message at the same instant compute the same key, and
             | the unique index turns the second insert into a duplicate-key
             | error the service resolves to the existing row. Without it you
             | get two threads for one pair and no clean way to merge them.
             |
             | Nullable because a group has no canonical pair.
             */
            $table->char('pair_key', 64)->nullable()->unique();

            /*
             | The highest sequence number handed out in this thread.
             |
             | Messages are ordered by a per-conversation counter rather than
             | by the global auto-increment id, for two reasons: the id must
             | never leave the server, and a counter that starts at 1 in every
             | thread is something the client can compare, paginate on and
             | store watermarks against without learning anything about the
             | rest of the database.
             */
            $table->unsignedInteger('last_seq')->default(0);

            // Denormalised pointer to the newest message, so the inbox can
            // eager-load previews without a correlated subquery per row.
            // Deliberately not a foreign key: messages reference conversations
            // and this would close the circle, making both tables undroppable.
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();

            // The inbox: threads a person is in, newest activity first. The
            // participant lookup supplies the ids; this orders them.
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
