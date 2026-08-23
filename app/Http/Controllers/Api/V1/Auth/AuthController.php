<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\SendOtpRequest;
use App\Http\Requests\Api\V1\Auth\VerifyOtpRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Otp\Exceptions\OtpException;
use App\Services\Otp\OtpService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Phone-first registration and sign-in.
 *
 * The account is created immediately but stays unverified — `phone_verified_at`
 * is null — until a code is confirmed. Nothing beyond verification is
 * reachable until then, and an unverified row lets someone resume a
 * half-finished signup instead of starting over.
 */
class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OtpService $otp)
    {
    }

    /**
     * POST /api/v1/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $result = DB::transaction(function () use ($data, $request) {
                $referrer = filled($data['referral_code'] ?? null)
                    ? User::where('referral_code', $data['referral_code'])->first()
                    : null;

                $user = User::create([
                    'name' => $data['name'],
                    'phone_country_code' => $data['phone_country_code'],
                    'phone_number' => $data['phone_number'],
                    'email' => $data['email'] ?? null,
                    'user_type' => $data['user_type'],
                    'education_stage' => $data['education_stage'] ?? null,
                    'referred_by' => $referrer?->id,
                    'device_token' => $data['device_token'] ?? null,
                    'device_type' => $data['device_type'] ?? null,
                    'device_id' => $data['device_id'] ?? null,
                    'device_model' => $data['device_model'] ?? null,
                    'app_version' => $data['app_version'] ?? null,
                ]);

                $issued = $this->otp->issue(
                    $user->phone_country_code,
                    $user->phone_number,
                    OtpCode::PURPOSE_REGISTRATION,
                    $user,
                    $request,
                );

                return ['user' => $user, 'issued' => $issued];
            });
        } catch (OtpException $e) {
            return $this->otpFailure($e);
        }

        return $this->created(
            $this->otpPayload($result['issued'], [
                'user' => new UserResource($result['user']),
            ]),
            'Account created. Enter the code we sent to verify your number.',
        );
    }

    /**
     * POST /api/v1/auth/otp/send
     *
     * Used both to resend during registration and to start a sign-in.
     */
    public function sendOtp(SendOtpRequest $request): JsonResponse
    {
        $countryCode = $request->string('phone_country_code')->toString();
        $number = $request->string('phone_number')->toString();
        $purpose = $request->purpose();

        $user = User::wherePhone($countryCode, $number)->first();

        // Signing in requires an account; registering must not reveal whether
        // one exists, so only the login path checks.
        if ($purpose === OtpCode::PURPOSE_LOGIN && $user === null) {
            return $this->fail(
                'No account found for that number.',
                ['phone_number' => ['No account found for that number.']],
                404,
            );
        }

        if ($user?->isBanned()) {
            return $this->fail('This account has been suspended.', null, 403);
        }

        try {
            $issued = $this->otp->issue($countryCode, $number, $purpose, $user, $request);
        } catch (OtpException $e) {
            return $this->otpFailure($e);
        }

        return $this->ok(
            $this->otpPayload($issued),
            'Verification code sent.',
        );
    }

    /**
     * POST /api/v1/auth/otp/verify
     *
     * On success the number is marked verified and a token is issued.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $countryCode = $request->string('phone_country_code')->toString();
        $number = $request->string('phone_number')->toString();

        try {
            $otp = $this->otp->verify(
                $countryCode,
                $number,
                $request->string('code')->toString(),
                $request->purpose(),
            );
        } catch (OtpException $e) {
            return $this->otpFailure($e);
        }

        $user = $otp->user ?? User::wherePhone($countryCode, $number)->first();

        if ($user === null) {
            return $this->fail('No account found for that number.', null, 404);
        }

        $user->forceFill([
            'phone_verified_at' => $user->phone_verified_at ?? now(),
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        // Register the device on the same call, so push works from the first
        // moment of the session.
        if ($request->filled('device_token')) {
            $user->forceFill([
                'device_token' => $request->input('device_token'),
                'device_type' => $request->input('device_type'),
                'device_id' => $request->input('device_id'),
            ]);
        }

        $user->save();

        return $this->ok($this->startSession($user, $request), 'Number verified.');
    }

    /**
     * Record the sign-in, register the device and mint a token.
     *
     * @return array<string, mixed>
     */
    private function startSession(User $user, $request): array
    {
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        if ($request->filled('device_token')) {
            $user->forceFill([
                'device_token' => $request->input('device_token'),
                'device_type' => $request->input('device_type'),
                'device_id' => $request->input('device_id'),
            ]);
        }

        $user->save();

        // One live token per user for now: a fresh sign-in drops the old one,
        // so a lost device cannot keep a session. Revisit when multi-device
        // support lands alongside the user_devices table.
        $user->tokens()->delete();

        return [
            'user' => new UserResource($user),
            'token' => $user->createToken(
                $request->input('device_id') ?? 'sfamily-app',
            )->plainTextToken,
            'token_type' => 'Bearer',
        ];
    }

    /**
     * POST /api/v1/auth/login
     *
     * Phone plus password, for users who set one. OTP remains the primary
     * route — see sendOtp/verifyOtp with purpose "login".
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $countryCode = $request->string('phone_country_code')->toString();
        $number = $request->string('phone_number')->toString();

        // Per-account throttle on top of the per-IP route limit, so someone
        // spraying one account from many addresses still gets stopped.
        $key = 'login:'.$countryCode.$number;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->fail(
                'Too many attempts. Try again in a minute.',
                ['reason' => 'throttled', 'retry_after' => RateLimiter::availableIn($key)],
                429,
            );
        }

        $user = User::wherePhone($countryCode, $number)->first();

        // Same response whether the number is unknown or the password is
        // wrong — otherwise this endpoint tells an attacker which numbers
        // are registered.
        if ($user === null
            || blank($user->password)
            || ! Hash::check($request->string('password')->toString(), $user->password)) {
            RateLimiter::hit($key, 60);

            return $this->fail(
                'Those details do not match our records.',
                ['reason' => 'invalid_credentials'],
                401,
            );
        }

        if ($user->isBanned()) {
            return $this->fail('This account has been suspended.', null, 403);
        }

        // An unverified number cannot sign in with a password — finish
        // verification first, so the account owner proved they hold the SIM.
        if (! $user->hasVerifiedPhone()) {
            return $this->fail(
                'Verify your number to continue.',
                ['reason' => 'phone_unverified'],
                403,
            );
        }

        RateLimiter::clear($key);

        return $this->ok(
            $this->startSession($user, $request),
            'Signed in.',
        );
    }

    /**
     * POST /api/v1/auth/logout  (auth:sanctum)
     */
    public function logout(): JsonResponse
    {
        auth()->user()?->currentAccessToken()?->delete();

        return $this->ok(null, 'Signed out.');
    }

    /**
     * Shape the "a code is on its way" payload consistently.
     *
     * @param  array{otp: OtpCode, code: ?string}  $issued
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function otpPayload(array $issued, array $extra = []): array
    {
        return array_merge($extra, [
            'otp' => array_filter([
                'expires_at' => $issued['otp']->expires_at->toIso8601String(),
                'expires_in' => (int) config('otp.ttl') * 60,
                'resend_after' => (int) config('otp.resend_cooldown'),
                'length' => (int) config('otp.length'),
                // Present only while no SMS provider is connected. Lets the
                // app show the code on screen during development.
                'debug_code' => $issued['code'],
            ], static fn ($v) => $v !== null),
        ]);
    }

    private function otpFailure(OtpException $e): JsonResponse
    {
        return $this->fail(
            $e->getMessage(),
            array_merge(['reason' => $e->reason], $e->context),
            $e->status,
        );
    }
}
