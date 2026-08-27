<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who has blocked whom.
 *
 * A table rather than an `is_blocked` flag on users, because blocking is not
 * a property of a person — it is a fact about a pair. "Is this account
 * blocked" has no answer; "has Faisal blocked Rahul" does. A boolean column
 * could only ever record that somebody was blocked by *someone*, which is
 * useless for deciding what any given viewer may see.
 *
 * Directed: one row means blocker_id will not see or hear from blocked_id.
 * The effects are enforced symmetrically at read time — neither party sees the
 * other — but only the blocker can lift it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocks', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();

            // Optional, for a future report flow. Never shown to the blocked
            // person — a block is deliberately not explained to its subject.
            $table->string('reason', 64)->nullable();

            $table->timestamp('blocked_at');
            $table->timestamps();

            // Blocking twice is the same block.
            $table->unique(['blocker_id', 'blocked_id']);

            // "Who have I blocked" — the settings list.
            $table->index(['blocker_id', 'blocked_at']);

            // "Has anyone blocked me" — checked on every profile read and
            // every search, so it needs its own index rather than relying on
            // the composite above.
            $table->index('blocked_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocks');
    }
};
