<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files, images and voice notes hanging off a message.
 *
 * Created now, written to in the final phase. Building the table with the
 * rest of the schema costs nothing today and means the media work is purely
 * additive when it arrives — no migration against a live messages table, no
 * backfill, no window of two shapes in flight at once.
 *
 * Its own table rather than columns on messages: an attachment has a dozen
 * fields that are null for every text message, and text messages are the
 * overwhelming majority of rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('message_id')->constrained()->cascadeOnDelete();

            // Which filesystem disk holds it, so moving media to S3 later is a
            // per-row fact rather than a global assumption.
            $table->string('disk', 32);
            $table->string('path', 255);

            $table->string('mime', 128);
            $table->unsignedBigInteger('size_bytes');

            // Images and video: lets a bubble reserve the right box before the
            // bytes arrive, the same trick the post grid uses.
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();

            // Audio and video.
            $table->unsignedInteger('duration_ms')->nullable();

            // A downsampled amplitude envelope for voice notes, computed on
            // the recording device. Lets the bubble draw its waveform without
            // downloading the audio first.
            $table->json('waveform')->nullable();

            // Generated on a queue after upload; the message is broadcast
            // again when it lands.
            $table->string('thumb_path', 255)->nullable();

            $table->timestamps();

            // No extra index on message_id: the foreign key already carries
            // one, and a duplicate would be written to on every insert for
            // nothing.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
