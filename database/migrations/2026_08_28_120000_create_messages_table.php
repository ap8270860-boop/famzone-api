<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The messages themselves.
 *
 * This becomes the largest table in the application, so every column here is
 * one that is genuinely needed and every column that might be needed later is
 * here already — an ALTER on a table with tens of millions of rows is an
 * outage, not a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();

            /*
             | Position within this thread, starting at 1.
             |
             | The ordering key, the pagination cursor and the unit both
             | watermarks are measured in. Assigned under a row lock on the
             | conversation, so two people sending at the same instant are
             | serialised into a genuine total order instead of racing for the
             | same number.
             |
             | created_at is not the ordering key: two messages in the same
             | second would be ambiguous, and cursor pagination across an
             | ambiguous order silently skips rows.
             */
            $table->unsignedInteger('seq');

            /*
             | The id the sending device generated before it had a server id.
             |
             | Makes sending idempotent. A client that times out and retries
             | sends the same client_uuid, the unique index below catches it,
             | and the service returns the original message instead of
             | creating a second one. Without this, every flaky connection
             | produces duplicates.
             */
            $table->char('client_uuid', 36);

            // text | image | file | audio | system.
            //
            // The media types are present from day one although nothing sends
            // them until the final phase, precisely so that phase needs no
            // schema change on this table.
            $table->string('type', 16)->default('text');

            $table->text('body')->nullable();

            // Reserved for replies. No foreign key: a reply must survive the
            // deletion of what it replied to, rendering as "message deleted"
            // rather than vanishing with it.
            $table->unsignedBigInteger('reply_to_id')->nullable();

            $table->timestamp('edited_at')->nullable();

            $table->timestamps();

            // Delete for everyone leaves the row in place so the recipient's
            // client can replace the bubble with a tombstone rather than
            // silently losing a line out of the middle of a conversation.
            $table->softDeletes();

            $table->unique(['conversation_id', 'client_uuid']);

            // Serves both the ordering and the cursor pagination, in both
            // directions. The foreign key's own index on conversation_id is
            // a prefix of this one, so no separate index is needed.
            $table->unique(['conversation_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
