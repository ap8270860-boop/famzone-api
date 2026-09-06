<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permission to see where somebody is, with an expiry on it.
 *
 * This table is the whole of the privacy model. Nothing anywhere else asks
 * "may this person see that person's location" — every such question is
 * answered by looking for a live row here, including the websocket
 * authorisation callback. One place to get right, and one place to audit.
 *
 * A row is live when ended_at is null and expires_at is either null or in
 * the future. Ended shares are kept rather than deleted: "who could see me
 * last Tuesday" is a question a safety app has to be able to answer, and a
 * deleted row cannot answer it.
 *
 * Two audiences, deliberately:
 *
 *  - `conversation` is the WhatsApp gesture. Bounded in time, bounded to the
 *    people already in that thread, and it announces itself with a message
 *    everyone can see. Nobody is ever tracked without a visible artefact.
 *  - `family` is the safety-app gesture: accepted family members, no
 *    expiry, ended only when the person ends it. Higher stakes, which is
 *    why it is a separate audience rather than a very long duration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_shares', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /** conversation | family */
            $table->string('audience', 16);

            /*
             | Set for the conversation audience, null for family.
             |
             | Not a polymorphic pair: there are two audiences and there will
             | not be twenty, and a nullable foreign key that the database can
             | actually enforce is worth more than the symmetry.
             */
            $table->foreignId('conversation_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            /*
             | The message that announced this share.
             |
             | nullOnDelete rather than cascade: deleting the announcement
             | must not silently revoke a share that is still running, or
             | "delete for everyone" on the bubble becomes a way to keep
             | broadcasting with nothing on screen to say so.
             */
            $table->foreignId('message_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->timestamp('started_at');

            /** Null means "until stopped" — only the family audience uses it. */
            $table->timestamp('expires_at')->nullable();

            /** Set when stopped early. Null on a share that ran its course. */
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();

            /*
             | The hot query, in two directions.
             |
             | "Is this person sharing right now" runs on every single ping,
             | so it leads with user_id and includes both liveness columns.
             */
            $table->index(['user_id', 'ended_at', 'expires_at'], 'shares_live');

            /** "What is live in this thread" — the chat bubble's own state. */
            $table->index(['conversation_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_shares');
    }
};
