<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pinning a chat, and marking one unread.
 *
 * Both go on the participant row rather than the conversation, because both
 * are one person's opinion about a thread two people are in. Pinning a chat
 * to the top of your own list says nothing about where it sits in theirs —
 * unlike a pinned *message*, which is shared and therefore lives on the
 * conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            /*
             | When this person pinned the thread.
             |
             | A timestamp rather than a boolean so several pinned chats keep
             | a stable order among themselves — most recently pinned first,
             | which is the only ordering anybody can predict.
             */
            $table->timestamp('pinned_at')->nullable()->after('muted_until');

            /*
             | "I have read this but I want it to look unread."
             |
             | Deliberately separate from unread_count and from the read
             | watermark. Moving the watermark back would turn the other
             | person's blue ticks grey again — telling them you un-read their
             | message, which is both untrue and none of their business.
             */
            $table->boolean('marked_unread')->default(false)->after('unread_count');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropColumn(['pinned_at', 'marked_unread']);
        });
    }
};
