<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Grow Laravel's stock users table into the SFamily user.
 *
 * Runs in three passes so it is safe on a table that already has rows:
 * add everything nullable, backfill the columns that must not stay null,
 * then tighten the constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- 1. Add the new columns -------------------------------------
        Schema::table('users', function (Blueprint $table) {
            // Public identifier. API responses and deep links expose this,
            // never the auto-increment id.
            $table->uuid('uuid')->nullable()->after('id');

            // Identity. Phone is the primary credential — OTP is the main way
            // in, so password becomes optional and email secondary.
            $table->string('username', 32)->nullable()->after('name');
            $table->string('phone_country_code', 8)->default('+91')->after('username');
            $table->string('phone_number', 20)->nullable()->after('phone_country_code');
            $table->timestamp('phone_verified_at')->nullable()->after('phone_number');

            // Profile
            $table->string('avatar_path')->nullable();      // S3 object key, not a URL
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();       // male|female|other|prefer_not_to_say
            $table->string('blood_group', 5)->nullable();   // shown on an SOS card
            $table->string('about', 160)->nullable();
            $table->string('locale', 10)->default('en');
            $table->string('timezone', 64)->default('Asia/Kolkata');

            // Presence and chat privacy
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_online')->default(false);
            $table->boolean('show_last_seen')->default(true);
            $table->boolean('show_online_status')->default(true);
            $table->boolean('show_read_receipts')->default(true);
            $table->boolean('allow_group_invites')->default(true);

            // Live location. Only the latest fix lives here, for "where is
            // everyone right now" map reads; breadcrumb history belongs in its
            // own table so this row stays cheap to update.
            // decimal(10,7) gives ~11mm precision over -180.0000000..180.
            $table->boolean('is_sharing_location')->default(false);
            $table->decimal('last_latitude', 10, 7)->nullable();
            $table->decimal('last_longitude', 10, 7)->nullable();
            $table->timestamp('last_location_at')->nullable();
            $table->unsignedTinyInteger('battery_level')->nullable();

            // SOS
            $table->string('emergency_message', 255)->nullable();
            $table->string('sos_pin')->nullable();          // hashed; cancels a false alarm
            $table->timestamp('last_sos_at')->nullable();

            // Device and push. TEXT because FCM registration tokens routinely
            // run past 255 characters.
            $table->text('device_token')->nullable();
            $table->string('device_type', 16)->nullable();  // android|ios|web
            $table->string('device_id', 191)->nullable();
            $table->string('device_model')->nullable();
            $table->string('app_version', 32)->nullable();
            $table->boolean('push_enabled')->default(true);

            // AI assistant quota — five free messages, then the paywall.
            $table->unsignedInteger('ai_messages_used')->default(0);
            $table->timestamp('ai_quota_reset_at')->nullable();

            // Subscription, denormalised so gating a request is a column read
            // rather than a join. Billing history gets its own table later.
            $table->boolean('is_premium')->default(false);
            $table->string('subscription_plan', 50)->nullable();
            $table->timestamp('subscription_expires_at')->nullable();

            // Account state and moderation
            $table->string('status', 20)->default('active'); // active|suspended|banned
            $table->timestamp('banned_at')->nullable();
            $table->string('ban_reason')->nullable();
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable(); // 45 chars fits IPv6

            // Referral
            $table->string('referral_code', 12)->nullable();
            $table->foreignId('referred_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->softDeletes();
        });

        // ---- 2. Backfill rows that already exist -------------------------
        // Any pre-existing user has no uuid or phone. Give them a uuid and a
        // placeholder phone derived from their id, so the NOT NULL and unique
        // constraints below can be applied.
        foreach (DB::table('users')->select('id')->orderBy('id')->cursor() as $row) {
            DB::table('users')->where('id', $row->id)->update([
                'uuid' => (string) Str::uuid7(),
                'phone_number' => str_pad((string) $row->id, 10, '0', STR_PAD_LEFT),
                'referral_code' => strtoupper(Str::random(8)),
            ]);
        }

        // ---- 3. Tighten -------------------------------------------------
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
            $table->string('phone_number', 20)->nullable(false)->change();

            // Phone is now the credential, so these two stop being required.
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();

            $table->unique('uuid');
            $table->unique('username');
            $table->unique('referral_code');

            // A phone number is only unique within its country code.
            $table->unique(['phone_country_code', 'phone_number']);

            // Map reads: circle members sharing, with a recent fix.
            $table->index(['is_sharing_location', 'last_location_at']);
            $table->index('last_seen_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['referred_by']);

            $table->dropUnique(['uuid']);
            $table->dropUnique(['username']);
            $table->dropUnique(['referral_code']);
            $table->dropUnique(['phone_country_code', 'phone_number']);

            $table->dropIndex(['is_sharing_location', 'last_location_at']);
            $table->dropIndex(['last_seen_at']);
            $table->dropIndex(['status']);

            $table->dropColumn([
                'uuid',
                'username',
                'phone_country_code',
                'phone_number',
                'phone_verified_at',
                'avatar_path',
                'date_of_birth',
                'gender',
                'blood_group',
                'about',
                'locale',
                'timezone',
                'last_seen_at',
                'is_online',
                'show_last_seen',
                'show_online_status',
                'show_read_receipts',
                'allow_group_invites',
                'is_sharing_location',
                'last_latitude',
                'last_longitude',
                'last_location_at',
                'battery_level',
                'emergency_message',
                'sos_pin',
                'last_sos_at',
                'device_token',
                'device_type',
                'device_id',
                'device_model',
                'app_version',
                'push_enabled',
                'ai_messages_used',
                'ai_quota_reset_at',
                'is_premium',
                'subscription_plan',
                'subscription_expires_at',
                'status',
                'banned_at',
                'ban_reason',
                'onboarding_completed_at',
                'last_login_at',
                'last_login_ip',
                'referral_code',
                'referred_by',
                'deleted_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
