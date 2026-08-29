<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let an attachment exist before its message does.
 *
 * Uploading is a separate step from sending, on purpose. A 20 MB file must
 * not hold a chat request open, and — more importantly — the message row is
 * not created until the bytes have landed. Broadcasting a message that points
 * at a file which does not exist yet gives every recipient a broken
 * attachment and no way to recover.
 *
 * So the row is written at upload time with no message, and adopted when the
 * message is sent. Two columns make that safe:
 *
 *   message_id  becomes nullable  — an orphan is a normal intermediate state
 *   user_id     records the uploader — so nobody can attach somebody else's
 *               file to their own message by guessing an id
 *
 * Orphans are swept up by `chat:prune-uploads`; see that command.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();

            // The name the sender's device gave it. Kept for documents, where
            // "Rent agreement.pdf" is most of the point of the attachment —
            // never used as a path, because it arrives from a client.
            $table->string('original_name', 160)->nullable()->after('mime');
        });

        // Dropped and re-added rather than changed in place: change() needs
        // doctrine/dbal for a foreign key column, and this table is empty —
        // nothing has been written to it yet — so the destructive route is
        // free here and will never be again.
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropForeign(['message_id']);
            $table->dropColumn('message_id');
        });

        Schema::table('message_attachments', function (Blueprint $table) {
            $table->foreignId('message_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Finding orphans to sweep: unattached rows, oldest first.
            $table->index(['message_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropIndex(['message_id', 'created_at']);
            $table->dropForeign(['user_id']);
            $table->dropColumn(['user_id', 'original_name']);
        });
    }
};
