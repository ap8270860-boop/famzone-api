<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Family membership — a second, stronger consent than following.
 *
 * Following is a social connection. Family membership is what will later mean
 * "can see my live location and receives my SOS", so it gets its own invite
 * and its own acceptance. Somebody agreeing to be followed has not agreed to
 * be tracked.
 *
 * Membership is stored one-directional (owner invites member) but read as
 * mutual: once accepted, each appears in the other's family list. Keeping one
 * row rather than two means an accept cannot half-apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_members', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // Who sent the invite.
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();

            // Who was invited, and who decides.
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();

            // pending | accepted | declined | removed
            $table->string('status', 16)->default('pending');

            // How the owner labels them: mother, father, sibling, child,
            // spouse, friend, other. Free-form rather than an enum — family
            // shapes vary more than any list we would write today.
            $table->string('relation', 32)->nullable();

            // Set by the member for the owner, so each side can label the
            // other independently. "My daughter" and "my mother" are the same
            // edge seen from two ends.
            $table->string('reverse_relation', 32)->nullable();

            $table->timestamp('invited_at');
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            $table->unique(['owner_id', 'member_id']);
            $table->index(['owner_id', 'status']);
            $table->index(['member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_members');
    }
};
