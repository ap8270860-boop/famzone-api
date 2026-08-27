<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Likes and tags.
 *
 * Both are pure join tables with a unique pair, which is what makes the
 * operations idempotent at the database level rather than in application
 * code: liking twice is a constraint violation the service catches, not a
 * race two requests can win together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_likes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamp('created_at')->nullable();

            // One like per person per post.
            $table->unique(['post_id', 'user_id']);

            // "Has this viewer liked these posts" — asked once per grid page,
            // batched over the page's post ids.
            $table->index(['user_id', 'post_id']);
        });

        Schema::create('post_tags', function (Blueprint $table) {
            $table->id();

            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->unique(['post_id', 'user_id']);

            // "Posts I am tagged in" — not surfaced yet, but the index costs
            // nothing now and a later ALTER on a large table is not free.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_tags');
        Schema::dropIfExists('post_likes');
    }
};
