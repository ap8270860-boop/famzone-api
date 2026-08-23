<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes issued against a phone number.
 *
 * Keyed on the phone rather than the user, so the same table serves
 * registration (user exists but is unverified), sign-in, and changing a
 * number later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();

            // Null until a user exists for this number.
            $table->foreignId('user_id')->nullable()
                ->constrained()->cascadeOnDelete();

            $table->string('phone_country_code', 8);
            $table->string('phone_number', 20);

            // Hashed, never plaintext. A dump of this table must not hand
            // anyone a working code.
            $table->string('code_hash');

            // registration | login | phone_change
            $table->string('purpose', 32)->default('registration');

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();

            // Audit trail — who asked, from where.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamps();

            // The hot path: newest unverified code for this number+purpose.
            $table->index(
                ['phone_country_code', 'phone_number', 'purpose', 'verified_at'],
                'otp_lookup_index'
            );
            $table->index('expires_at');   // for the pruning job
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
