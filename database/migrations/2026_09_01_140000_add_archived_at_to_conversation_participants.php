<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archiving a chat.
 *
 * A column of its own rather than a third value in `state`, even though the
 * model already carries an ARCHIVED constant. State answers "has this person
 * accepted the thread" — pending threads sit in the Requests tab because of
 * it — and folding archiving into the same column would make an archived
 * chat stop being accepted, which is not what archiving means.
 *
 * A timestamp rather than a boolean, matching pinned_at: it costs nothing and
 * answers "when did this get put away" the first time anybody asks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('pinned_at');

            /*
             | Every inbox query now filters on this.
             |
             | Composite and leading on user_id, because the question is
             | always "this person's threads, archived or not" — never
             | "everybody's archived threads".
             */
            $table->index(['user_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
