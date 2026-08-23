<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the account is for.
 *
 * Drives feature gating rather than being cosmetic: a child account gets
 * parental controls and no payment surface, a senior account gets the
 * simplified SOS and larger type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // adult | kid | senior — one value, because they are mutually
            // exclusive. Two booleans would allow "kid and senior at once".
            $table->string('user_type', 20)->default('adult')->after('date_of_birth');

            // school | college — only meaningful when user_type is 'kid',
            // null otherwise.
            $table->string('education_stage', 20)->nullable()->after('user_type');

            // Filtering a circle by account type is a common read.
            $table->index('user_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['user_type']);
            $table->dropColumn(['user_type', 'education_stage']);
        });
    }
};
