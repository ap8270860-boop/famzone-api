<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make people search survive more than a few thousand users.
 *
 * Search matches a prefix — `name LIKE 'fai%'` — which MySQL can serve from a
 * B-tree index. A leading-wildcard `LIKE '%fai%'` cannot use one at all and
 * degrades to a full scan, which is why the search deliberately does not offer
 * mid-word matching.
 *
 * username is already indexed by its unique constraint, and the phone pair by
 * its own. Only name is missing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index('name', 'users_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_name_index');
        });
    }
};
