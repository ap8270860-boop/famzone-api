<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group chats.
 *
 * The table already carried `type` and a nullable `pair_key` — a group has no
 * canonical pair, which is why that unique key was made nullable on day one.
 * What it never had is the three things a group needs that a direct thread
 * does not: a name, a picture, and somebody who made it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            /*
             | A group's name. Null for direct threads, which are named after
             | whoever is on the other side and cannot be renamed.
             */
            $table->string('title', 80)->nullable()->after('type');

            // Streamed through a signed URL like every other private file,
            // so the path is an internal detail and never reaches a client.
            $table->string('avatar_path', 255)->nullable()->after('title');

            /*
             | Who made it.
             |
             | nullOnDelete rather than cascade: a group outlives the account
             | that created it. Deleting the creator must not delete the
             | conversation everybody else is still in.
             */
            $table->foreignId('created_by_id')->nullable()->after('avatar_path')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('conversation_participants', function (Blueprint $table) {
            /*
             | admin | member.
             |
             | Added now, before there is anything to administer. Admin
             | controls are the next piece of work, and a role column that
             | arrives after the first groups exist means backfilling
             | ownership by guesswork.
             */
            $table->string('role', 12)->default('member')->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['created_by_id']);
            $table->dropColumn(['title', 'avatar_path', 'created_by_id']);
        });
    }
};
