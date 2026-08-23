<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second, decoy profile picture.
 *
 * Shown to anyone outside the user's circles. Someone who does not want their
 * real face visible to strangers — which is most of the point of a safety
 * app — can keep a neutral image public and the real one private, instead of
 * having to choose between the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('alternate_avatar_path')->nullable()->after('avatar_path');

            // Which picture strangers see. When false the avatar is simply
            // hidden from them rather than replaced.
            $table->boolean('use_alternate_avatar')->default(false)
                ->after('alternate_avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['alternate_avatar_path', 'use_alternate_avatar']);
        });
    }
};
