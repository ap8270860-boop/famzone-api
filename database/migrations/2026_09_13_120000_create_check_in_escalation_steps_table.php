<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One person's place in one chain.
 *
 * A snapshot, not a reference. The row carries its own position rather than
 * reading it back from check_in_contacts, because the list this was copied
 * from is a live preference the owner can rearrange at any moment — and a
 * progress bar that reorders itself under somebody's finger because the owner
 * edited their contacts on another phone is not a progress bar, it is a bug
 * that is very hard to believe when it is reported.
 *
 * This is also the row the notification feed derives its buttons from. There
 * is no "accepted" flag stored on the notification: the feed asks this table
 * whether the step is still waiting for an answer, exactly as follow requests
 * and family invites already work. Accept from the websocket banner and the
 * Accept button in the feed disappears, because there was only ever one fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_in_escalation_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('check_in_escalation_id')
                ->constrained('check_in_escalations')
                ->cascadeOnDelete();

            // Who this step notifies.
            $table->foreignId('contact_id')->constrained('users')->cascadeOnDelete();

            // 1-based, matching check_in_escalations.current_step.
            $table->unsignedTinyInteger('position');

            /*
             | waiting  — their turn has not come
             | notified — the request is with them now
             | accepted — they confirmed they know
             | rejected — they passed it along
             | expired  — the wait ran out with no answer
             | skipped  — somebody earlier accepted, so this never went out
             */
            $table->string('status', 16)->default('waiting');

            $table->timestamp('notified_at')->nullable();
            $table->timestamp('responded_at')->nullable();

            /*
             | The feed entry this step produced.
             |
             | Kept so responding from the websocket banner can mark the
             | matching notification read, rather than leaving a row in the
             | feed offering to accept something already accepted. nullOnDelete
             | because the notification is the disposable half of the pair —
             | the step is the record.
             */
            $table->foreignId('user_notification_id')
                ->nullable()
                ->constrained('user_notifications')
                ->nullOnDelete();

            $table->timestamps();

            // A position appears once per chain. This one *is* worth enforcing:
            // steps are written once, in order, inside a transaction, so there
            // is no permutation to shuffle through.
            $table->unique(['check_in_escalation_id', 'position']);

            // "What is waiting for my answer" — the badge on the recipient's
            // side, and the lookup behind responding.
            $table->index(['contact_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_in_escalation_steps');
    }
};
