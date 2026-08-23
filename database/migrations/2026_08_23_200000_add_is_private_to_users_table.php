<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public or private accounts.
 *
 * Default false — public. Following a public account is instant, the way it is
 * on Instagram; following a private one creates a request the owner answers.
 *
 * Note what this does NOT gate: family membership. Adding somebody to your
 * family is a separate invite either way, because that is the consent that
 * will carry location and SOS visibility. Going public loosens who can see
 * your profile, never who can see where you are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_private')
                ->default(false)
                ->after('allow_group_invites');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_private');
        });
    }
};
