<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Starring, pinning and forwarding.
 *
 * Three features in one migration because they arrive together, but they are
 * three genuinely different shapes and the schema says so:
 *
 *  - a star is private to one person, so it is its own table keyed on the
 *    pair;
 *  - a pin is shared by everyone in the thread, so it lives on the
 *    conversation;
 *  - a forward creates a new message, so all it needs is a flag on that
 *    message saying where it came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | Starred messages.
         |
         | Its own table rather than a column, because a star belongs to one
         | person and a message has many readers. Two people can star the same
         | message without either knowing.
         */
        Schema::create('message_stars', function (Blueprint $table) {
            $table->id();

            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamp('created_at')->nullable();

            // Starring twice is the same as starring once.
            $table->unique(['message_id', 'user_id']);

            // The Starred screen: everything one person kept, newest first.
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            /*
             | The pinned message, shared by both people.
             |
             | One at a time. Multiple pins mean an ordering question, a list
             | UI and a "which one do we show in the banner" argument, for a
             | feature that in a two-person thread is almost always used to
             | keep one address or one date to hand.
             |
             | No foreign key: the pinned message can be soft-deleted, and a
             | cascade would quietly unpin things the moment somebody tidied
             | up. The service clears it deliberately instead.
             */
            $table->unsignedBigInteger('pinned_message_id')->nullable()->after('last_message_at');
            $table->foreignId('pinned_by_id')->nullable()->after('pinned_message_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('pinned_at')->nullable()->after('pinned_by_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            /*
             | Whether this message was forwarded from somewhere else.
             |
             | A flag, not a pointer to the original. The label only needs to
             | say "Forwarded"; recording which conversation it came out of
             | would let the recipient learn about a thread they are not in.
             */
            $table->boolean('forwarded')->default(false)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('forwarded');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['pinned_by_id']);
            $table->dropColumn(['pinned_message_id', 'pinned_by_id', 'pinned_at']);
        });

        Schema::dropIfExists('message_stars');
    }
};
