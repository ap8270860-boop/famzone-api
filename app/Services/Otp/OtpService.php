<?php

namespace App\Services\Otp;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\Otp\Contracts\OtpSender;
use App\Services\Otp\Exceptions\OtpException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Issuing and checking one-time codes.
 *
 * All the rules live here — rate limits, expiry, attempt counting — so
 * controllers stay thin and the same guarantees apply wherever an OTP is
 * used.
 */
class OtpService
{
    public function __construct(private readonly OtpSender $sender)
    {
    }

    /**
     * Issue a code and hand it to the delivery channel.
     *
     * @return array{otp: OtpCode, code: ?string}  `code` is non-null only
     *         when the config allows echoing it back (development).
     *
     * @throws OtpException
     */
    public function issue(
        string $countryCode,
        string $number,
        string $purpose = OtpCode::PURPOSE_REGISTRATION,
        ?User $user = null,
        ?Request $request = null,
    ): array {
        $this->guardRateLimits($countryCode, $number, $purpose);

        $code = $this->generateCode();

        $otp = DB::transaction(function () use (
            $countryCode, $number, $purpose, $user, $request, $code
        ) {
            // Only one code may be live at a time. Expiring the previous ones
            // stops a user with three unread texts guessing which is current,
            // and closes the window where an older code still works.
            OtpCode::query()
                ->forPhone($countryCode, $number)
                ->where('purpose', $purpose)
                ->usable()
                ->update(['expires_at' => now()]);

            return OtpCode::create([
                'user_id' => $user?->id,
                'phone_country_code' => $countryCode,
                'phone_number' => $number,
                'code_hash' => Hash::make($code),
                'purpose' => $purpose,
                'expires_at' => now()->addMinutes((int) config('otp.ttl')),
                'ip_address' => $request?->ip(),
                'user_agent' => substr((string) $request?->userAgent(), 0, 255),
            ]);
        });

        $this->sender->send($countryCode.$number, $code);

        return [
            'otp' => $otp,
            'code' => $this->mayExposeCode() ? $code : null,
        ];
    }

    /**
     * Check a code and consume it.
     *
     * @throws OtpException
     */
    public function verify(
        string $countryCode,
        string $number,
        string $code,
        string $purpose = OtpCode::PURPOSE_REGISTRATION,
    ): OtpCode {
        /** @var OtpCode|null $otp */
        $otp = OtpCode::query()
            ->forPhone($countryCode, $number)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if ($otp === null) {
            throw OtpException::notFound();
        }

        if ($otp->isExpired()) {
            throw OtpException::expired();
        }

        if (! $otp->hasAttemptsLeft()) {
            throw OtpException::attemptsExhausted();
        }

        // Count the attempt before checking, so a crash mid-check cannot be
        // used to get a free guess.
        $otp->increment('attempts');

        if (! Hash::check($code, $otp->code_hash)) {
            if (! $otp->fresh()->hasAttemptsLeft()) {
                throw OtpException::attemptsExhausted();
            }

            throw OtpException::incorrect($otp->fresh()->attemptsRemaining());
        }

        $otp->forceFill(['verified_at' => now()])->save();

        return $otp;
    }

    /**
     * Seconds until this number may request another code, or null if it can
     * request one now.
     */
    public function cooldownRemaining(string $countryCode, string $number, string $purpose): ?int
    {
        $last = OtpCode::query()
            ->forPhone($countryCode, $number)
            ->where('purpose', $purpose)
            ->latest('id')
            ->first();

        if ($last === null) {
            return null;
        }

        $elapsed = $last->created_at->diffInSeconds(now());
        $cooldown = (int) config('otp.resend_cooldown');

        return $elapsed >= $cooldown ? null : (int) ceil($cooldown - $elapsed);
    }

    /**
     * Whether the plaintext code may be returned to the client.
     *
     * Two independent gates: the config flag, and a hard runtime check that
     * this is not production. Cached config cannot leak codes on a live
     * server even if the flag was baked in as true.
     */
    private function mayExposeCode(): bool
    {
        return (bool) config('otp.expose_in_response') && ! app()->isProduction();
    }

    /** @throws OtpException */
    private function guardRateLimits(string $countryCode, string $number, string $purpose): void
    {
        if ($seconds = $this->cooldownRemaining($countryCode, $number, $purpose)) {
            throw OtpException::tooSoon($seconds);
        }

        $inLastHour = OtpCode::query()
            ->forPhone($countryCode, $number)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($inLastHour >= (int) config('otp.hourly_limit')) {
            throw OtpException::hourlyLimit();
        }
    }

    /**
     * A numeric code of the configured length.
     *
     * `random_int` rather than `rand`: this is a credential, so it has to come
     * from a cryptographically secure source.
     */
    private function generateCode(): string
    {
        $length = (int) config('otp.length');
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}
