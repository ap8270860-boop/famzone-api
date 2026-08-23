<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The in-app notification feed.
 *
 * Named user_notifications rather than notifications so it cannot collide with
 * Laravel's own DatabaseNotification table, which we will want later for push
 * delivery. This one is the feed the user reads; that one is a delivery log.
 *
 * The important design decision is what is NOT stored here: whether a request
 * has been accepted. A notification row records that something happened, and
 * nothing more. Whether the Accept button still shows is derived from the
 * follow or family row it points at, so a request accepted from the profile
 * screen cannot leave a stale Accept button sitting in the feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // Who sees it.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Who caused it. Nullable for system notices with no human behind
            // them.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // follow.requested | follow.accepted | family.invited |
            // family.accepted — dotted so a client can match on the prefix.
            $table->string('type', 48);

            // The row this notification is about, so the client can resolve
            // the live state of the request rather than trusting the feed.
            $table->string('subject_type', 48)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // Rendered copy plus anything type-specific.
            $table->json('data')->nullable();

            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // The feed itself: newest first for one user.
            $table->index(['user_id', 'created_at']);

            // The unread badge, which is polled far more often than the feed
            // is opened.
            $table->index(['user_id', 'read_at']);

            // Finding the notification attached to a given request, so
            // responding from the profile screen can resolve the feed entry.
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
