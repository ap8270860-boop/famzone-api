<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * An SFamily end user.
 *
 * Authentication is phone-first: the OTP flow is the primary way in, so
 * `password` is nullable and `email` is optional.
 *
 * NOTE — device columns. `device_token` / `device_type` live on this row,
 * which assumes one device per user. That breaks the moment somebody signs in
 * on a tablet as well as a phone: the second login overwrites the first token
 * and the first device silently stops receiving push. Before launch these
 * should move to a `user_devices` table with a row per device. The columns are
 * kept here for now because that is what the current app sends.
 */
#[Fillable([
    'name',
    'username',
    'phone_country_code',
    'phone_number',
    'email',
    'password',
    'avatar_path',
    'alternate_avatar_path',
    'use_alternate_avatar',
    'date_of_birth',
    'user_type',
    'education_stage',
    'gender',
    'blood_group',
    'about',
    'locale',
    'timezone',
    'show_last_seen',
    'show_online_status',
    'show_read_receipts',
    'allow_group_invites',
    'is_sharing_location',
    'emergency_message',
    'device_token',
    'device_type',
    'device_id',
    'device_model',
    'app_version',
    'push_enabled',
    'referred_by',
])]
#[Hidden([
    'password',
    'remember_token',
    'sos_pin',
    'device_token',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    // Allowed values for the free-text state columns. Kept as constants rather
    // than DB enums so adding one later is a code change, not a table rebuild.
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_BANNED = 'banned';

    // Who the account is for. Mutually exclusive, so one column rather than
    // a pair of booleans that could both be true.
    public const TYPE_ADULT = 'adult';
    public const TYPE_KID = 'kid';
    public const TYPE_SENIOR = 'senior';

    // Only meaningful when user_type is TYPE_KID.
    public const STAGE_SCHOOL = 'school';
    public const STAGE_COLLEGE = 'college';

    public const DEVICE_ANDROID = 'android';
    public const DEVICE_IOS = 'ios';
    public const DEVICE_WEB = 'web';

    /** Free AI assistant messages before the paywall. */
    public const AI_FREE_MESSAGE_LIMIT = 5;

    /**
     * How long an avatar link stays valid.
     *
     * Short enough that a leaked link goes stale, long enough that a client
     * which opened the app in the morning still renders at lunchtime.
     */
    public const MEDIA_LINK_HOURS = 6;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_check_in_at' => 'datetime',
            'check_in_streak' => 'integer',
            'longest_check_in_streak' => 'integer',
            'sos_pin' => 'hashed',

            'date_of_birth' => 'date',

            'last_seen_at' => 'datetime',
            'is_online' => 'boolean',
            'show_last_seen' => 'boolean',
            'show_online_status' => 'boolean',
            'show_read_receipts' => 'boolean',
            'allow_group_invites' => 'boolean',

            'use_alternate_avatar' => 'boolean',
            'is_sharing_location' => 'boolean',
            'last_latitude' => 'decimal:7',
            'last_longitude' => 'decimal:7',
            'last_location_at' => 'datetime',
            'battery_level' => 'integer',

            'last_sos_at' => 'datetime',

            'push_enabled' => 'boolean',

            'ai_messages_used' => 'integer',
            'ai_quota_reset_at' => 'datetime',

            'is_premium' => 'boolean',
            'subscription_expires_at' => 'datetime',

            'banned_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Assign the public identifier and referral code on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->uuid ??= (string) Str::uuid7();
            $user->referral_code ??= static::generateReferralCode();
        });
    }

    /**
     * A short, unambiguous code — no O/0 or I/1 to confuse anyone typing it in.
     */
    protected static function generateReferralCode(): string
    {
        do {
            $code = substr(str_shuffle(str_repeat('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', 2)), 0, 8);
        } while (static::withTrashed()->where('referral_code', $code)->exists());

        return $code;
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /** The user who referred this one, if any. */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by');
    }

    /** Users who signed up with this user's referral code. */
    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referred_by');
    }


    /**
     * @return HasMany<SafetyCheckIn, User>
     */
    public function checkIns(): HasMany
    {
        return $this->hasMany(SafetyCheckIn::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /** E.164 phone number, e.g. +919876543210. */
    protected function fullPhoneNumber(): Attribute
    {
        return Attribute::get(
            fn (): string => $this->phone_country_code.$this->phone_number,
        );
    }

    /** Link to the avatar, or null when none is set. */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->mediaUrl('primary', $this->avatar_path),
        );
    }

    /** Link to the decoy avatar shown outside the user's circles. */
    protected function alternateAvatarUrl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->mediaUrl('alternate', $this->alternate_avatar_path),
        );
    }

    /**
     * Build a link the app can hand straight to an <img> tag.
     *
     * Avatars are stored privately and must stay that way — this is a safety
     * app, and the alternate-avatar feature exists precisely so that a real
     * photo is not visible to everyone. So there is no public URL to hand out.
     *
     * S3 can sign a link directly to the object, which is the cheapest path:
     * the file is served without PHP in the way. Local disks cannot sign, and
     * their root (storage/app/private) is not served by nginx at all, so they
     * get a signed link to a route that streams the file instead.
     *
     * Either way the link expires, which is why clients must re-read it from
     * /me rather than caching it in their own storage.
     */
    protected function mediaUrl(string $slot, ?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $disk = Storage::disk(config('filesystems.default'));

        try {
            if ($disk->providesTemporaryUrls()) {
                return $disk->temporaryUrl($path, now()->addHours(self::MEDIA_LINK_HOURS));
            }
        } catch (\Throwable) {
            // Fall through — a disk that claims to sign but cannot is still
            // servable through the streaming route below.
        }

        return URL::temporarySignedRoute(
            'api.v1.media.avatar',
            now()->addHours(self::MEDIA_LINK_HOURS),
            ['uuid' => $this->uuid, 'slot' => $slot],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isBanned(): bool
    {
        return $this->status === self::STATUS_BANNED;
    }

    public function isKid(): bool
    {
        return $this->user_type === self::TYPE_KID;
    }

    public function isSenior(): bool
    {
        return $this->user_type === self::TYPE_SENIOR;
    }

    public function isAdult(): bool
    {
        return $this->user_type === self::TYPE_ADULT;
    }

    /**
     * Accounts that need a guardian watching over them. Used to decide
     * whether payments, public discovery and open group invites are shown.
     */
    public function needsGuardian(): bool
    {
        return $this->isKid();
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /** True while the subscription is both flagged and unexpired. */
    public function hasActiveSubscription(): bool
    {
        return $this->is_premium
            && ($this->subscription_expires_at === null
                || $this->subscription_expires_at->isFuture());
    }

    /** Whether another AI assistant message is allowed without paying. */
    public function hasAiQuotaRemaining(): bool
    {
        return $this->hasActiveSubscription()
            || $this->ai_messages_used < self::AI_FREE_MESSAGE_LIMIT;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /** @param  Builder<User>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /** @param  Builder<User>  $query */
    public function scopeVerified(Builder $query): void
    {
        $query->whereNotNull('phone_verified_at');
    }

    /**
     * Users currently broadcasting a location, with a fix recent enough to
     * be worth drawing on a map.
     *
     * @param  Builder<User>  $query
     */
    public function scopeSharingLocation(Builder $query, int $withinMinutes = 15): void
    {
        $query->where('is_sharing_location', true)
            ->where('last_location_at', '>=', now()->subMinutes($withinMinutes));
    }

    /**
     * @param  Builder<User>  $query
     * @param  string|array<int, string>  $type
     */
    public function scopeOfType(Builder $query, string|array $type): void
    {
        $query->whereIn('user_type', (array) $type);
    }

    /** @param  Builder<User>  $query */
    public function scopePushable(Builder $query): void
    {
        $query->where('push_enabled', true)->whereNotNull('device_token');
    }

    /**
     * Look a user up by the phone number they typed.
     *
     * @param  Builder<User>  $query
     */
    public function scopeWherePhone(Builder $query, string $countryCode, string $number): void
    {
        $query->where('phone_country_code', $countryCode)
            ->where('phone_number', $number);
    }
}
