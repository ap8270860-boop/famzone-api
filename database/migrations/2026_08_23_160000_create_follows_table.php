<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Directed follow edges, with consent.
 *
 * One row is one direction: A following B says nothing about B following A.
 * Mutual follows are two rows, which is what makes "follows you" and "you
 * follow" independently answerable — the thing a follow UI has to get right.
 *
 * Rows are never deleted on decline. A declined row is kept so a rejected
 * request cannot be spammed straight back, and so accepting later is an
 * update rather than a fresh insert. Unfollowing does delete, because that is
 * the user withdrawing their own edge rather than a decision about someone
 * else's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // The person doing the following.
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();

            // The person being followed, who decides whether it happens.
            $table->foreignId('followee_id')->constrained('users')->cascadeOnDelete();

            // pending | accepted | declined
            $table->string('status', 16)->default('pending');

            $table->timestamp('requested_at');
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            // One edge per ordered pair. Re-following is an update.
            $table->unique(['follower_id', 'followee_id']);

            // "Who am I following, and is it accepted yet" — the profile and
            // search screens both ask this.
            $table->index(['follower_id', 'status']);

            // "Who follows me, and who is waiting on my approval" — the
            // notification badge and the followers list.
            $table->index(['followee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};
