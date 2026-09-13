<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who to tell, and in what order, when somebody checks in.
 *
 * One list per user, chosen once and edited afterwards. It is a *preference*,
 * not a record — which is the reason it is a separate table from the run that
 * actually happens. Reordering this list must never rewrite the history of a
 * check-in that has already gone out, so check_in_escalation_steps holds a
 * copy taken at the moment the chain started and this table is free to change
 * underneath it.
 *
 * Position is not unique.
 *
 * It looks like it should be, and the constraint would be honest about the
 * intent — but a reorder is a permutation, and a permutation applied row by
 * row passes through states where two rows briefly share a position. Enforcing
 * uniqueness would mean either a deferred constraint (which MySQL does not
 * have) or a shuffle through temporary values, both to police an invariant
 * that one transaction in one service already guarantees. The list is rewritten
 * wholesale, never patched, so there is no second writer to protect against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_in_contacts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // Whose list this is.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             | The family member to notify.
             |
             | cascadeOnDelete rather than nullOnDelete: a contact row with no
             | contact is not a contact. If the account goes, the entry goes,
             | and the owner's list is simply one shorter next time they open
             | it.
             */
            $table->foreignId('contact_id')->constrained('users')->cascadeOnDelete();

            // 1-based. First in the list is notified first.
            $table->unsignedTinyInteger('position');

            $table->timestamps();

            // Somebody can only appear in their own list once — this is the
            // constraint that actually matters, and it is cheap to enforce.
            $table->unique(['user_id', 'contact_id']);

            // The read: the whole list for one user, already ordered.
            $table->index(['user_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_in_contacts');
    }
};
