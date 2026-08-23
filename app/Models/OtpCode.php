<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time code issued against a phone number.
 *
 * The code itself is only ever stored hashed — compare with
 * `Hash::check($plain, $otp->code_hash)`.
 */
class OtpCode extends Model
{
    public const PURPOSE_REGISTRATION = 'registration';
    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_PHONE_CHANGE = 'phone_change';

    protected $fillable = [
        'user_id',
        'phone_country_code',
        'phone_number',
        'code_hash',
        'purpose',
        'attempts',
        'expires_at',
        'verified_at',
        'ip_address',
        'user_agent',
    ];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < (int) config('otp.max_attempts');
    }

    /** Attempts still available before the code is burned. */
    public function attemptsRemaining(): int
    {
        return max(0, (int) config('otp.max_attempts') - $this->attempts);
    }

    /** @param  Builder<OtpCode>  $query */
    public function scopeForPhone(Builder $query, string $countryCode, string $number): void
    {
        $query->where('phone_country_code', $countryCode)
            ->where('phone_number', $number);
    }

    /** Unverified and not yet expired. @param  Builder<OtpCode>  $query */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('verified_at')->where('expires_at', '>', now());
    }
}
