<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A photo post.
 *
 * One image per post for now. The column is `image_path` rather than a
 * separate media table because a carousel is a different feature with
 * different ordering and cover-image questions, and modelling for it before
 * building it would mean carrying a join nothing needs yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('image_path', 255);

            // Stored so a grid can reserve the right box before the bytes
            // arrive. Square today, but recording the real values means a
            // later aspect-ratio change needs no backfill.
            $table->unsignedSmallInteger('image_width');
            $table->unsignedSmallInteger('image_height');

            // 2200 matches what people are used to elsewhere and is well
            // within a TEXT column.
            $table->text('caption')->nullable();

            // published | archived | removed. Removal is a state, not a
            // delete: a post reported and taken down still needs to be
            // findable by moderation.
            $table->string('status', 16)->default('published');

            // Denormalised, maintained inside the like transaction. A grid of
            // 30 posts would otherwise be 30 count queries.
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('tags_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // The profile grid: one user's posts, newest first.
            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
