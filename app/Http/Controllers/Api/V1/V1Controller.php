<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\SendOtpRequest;
use App\Http\Requests\Api\V1\Auth\VerifyOtpRequest;
use App\Http\Requests\Api\V1\Profile\UpdateAvatarRequest;
use App\Http\Requests\Api\V1\Safety\CheckInRequest;
use App\Http\Requests\Api\V1\Social\FamilyInviteRequest;
use App\Http\Requests\Api\V1\Social\RespondRequest;
use App\Http\Requests\Api\V1\Profile\UpdatePasswordRequest;
use App\Http\Requests\Api\V1\Profile\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Otp\Exceptions\OtpException;
use App\Services\Otp\OtpService;
use App\Services\Profile\UsernameChecker;
use App\Services\Safety\SafetyService;
use App\Services\Social\NotificationService;
use App\Services\Social\RelationshipService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The v1 API.
 *
 * Every endpoint the mobile app and the web dashboard call lives here.
 * Validation sits in Form Requests, business rules in services
 * (OtpService, UsernameChecker) and output shape in UserResource — so this
 * class stays a thin router between them rather than growing logic of its own.
 */
class V1Controller extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OtpService $otp,
        private readonly UsernameChecker $usernames,
        private readonly SafetyService $safety,
        private readonly RelationshipService $relationships,
        private readonly NotificationService $notifier,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Diagnostics
    |--------------------------------------------------------------------------
    */

    /**
     * GET /api/v1/ping
     *
     * Confirms the API is reachable and booted cleanly.
     */
    public function ping(Request $request): JsonResponse
    {
        return $this->ok([
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'api_version' => 'v1',
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
            'server_time' => now()->toIso8601String(),
        ], 'pong');
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    /**
     * POST /api/v1/auth/register
     *
     * The account is created immediately but stays unverified —
     * `phone_verified_at` is null — until a code is confirmed. An unverified
     * row lets somebody resume a half-finished signup instead of starting over.
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
        // verification first, so the owner proved they hold the SIM.
        if (! $user->hasVerifiedPhone()) {
            return $this->fail(
                'Verify your number to continue.',
                ['reason' => 'phone_unverified'],
                403,
            );
        }

        RateLimiter::clear($key);

        return $this->ok($this->startSession($user, $request), 'Signed in.');
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

        return $this->ok($this->otpPayload($issued), 'Verification code sent.');
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
        ])->save();

        return $this->ok($this->startSession($user, $request), 'Number verified.');
    }

    /**
     * POST /api/v1/auth/logout  (auth:sanctum)
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return $this->ok(null, 'Signed out.');
    }

    /*
    |--------------------------------------------------------------------------
    | Profile
    |--------------------------------------------------------------------------
    */

    /**
     * GET /api/v1/me
     * GET /api/v1/profile
     */
    public function me(Request $request): JsonResponse
    {
        return $this->ok(new UserResource($request->user()), 'OK');
    }

    /**
     * PATCH /api/v1/profile
     *
     * Partial by design: the app sends only what changed.
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        // Changing the email un-verifies it — the new address has not been
        // proven, and keeping the old timestamp would claim otherwise.
        if (array_key_exists('email', $data) && $data['email'] !== $user->email) {
            $user->email_verified_at = null;
        }

        $user->fill($data)->save();

        return $this->ok(new UserResource($user->fresh()), 'Profile updated.');
    }

    /**
     * GET /api/v1/profile/username/check?username=...
     *
     * Called while the user types, so it stays cheap: one indexed lookup, and
     * suggestions only when the name is actually taken.
     */
    public function checkUsername(Request $request): JsonResponse
    {
        $username = $this->usernames->normalise((string) $request->query('username', ''));

        if ($username === '') {
            return $this->fail('Enter a username.', ['reason' => 'empty'], 422);
        }

        if ($reason = $this->usernames->reject($username)) {
            return $this->ok([
                'username' => $username,
                'available' => false,
                'reason' => $reason[0],
                'message' => $reason[1],
                'suggestions' => [],
            ], $reason[1]);
        }

        $user = $request->user();

        // The name you already hold always reads as available, otherwise
        // opening your own profile shows your own username as taken.
        if ($user !== null && $user->username === $username) {
            return $this->ok([
                'username' => $username,
                'available' => true,
                'reason' => 'current',
                'message' => 'This is your current username.',
                'suggestions' => [],
            ], 'Available');
        }

        $available = $this->usernames->isAvailable($username, $user?->id);

        return $this->ok([
            'username' => $username,
            'available' => $available,
            'reason' => $available ? 'available' : 'taken',
            'message' => $available
                ? '@'.$username.' is available.'
                : '@'.$username.' is already taken.',
            'suggestions' => $available ? [] : $this->usernames->suggest($username),
        ], $available ? 'Available' : 'Taken');
    }

    /**
     * POST /api/v1/profile/password
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (filled($user->password)
            && ! Hash::check($request->string('current_password')->toString(), $user->password)) {
            return $this->fail(
                'Your current password is not correct.',
                ['current_password' => ['Your current password is not correct.']],
                422,
            );
        }

        $user->forceFill([
            'password' => $request->string('password')->toString(),
        ])->save();

        // Every other session is now stale. Leaving them alive would mean a
        // password change does not actually lock anyone out.
        $current = $request->user()->currentAccessToken();
        $user->tokens()->where('id', '!=', $current?->id)->delete();

        return $this->ok(null, 'Password updated. Other devices were signed out.');
    }

    /**
     * POST /api/v1/profile/avatar   (multipart: avatar, slot)
     */
    public function uploadAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        $user = $request->user();
        $slot = $request->slot();
        $column = $slot === 'alternate' ? 'alternate_avatar_path' : 'avatar_path';

        $disk = Storage::disk(config('filesystems.default'));

        $path = "avatars/{$user->uuid}/".Str::uuid7().'.'
            .$request->file('avatar')->extension();

        $disk->putFileAs(
            dirname($path),
            $request->file('avatar'),
            basename($path),
            ['visibility' => 'private'],
        );

        $previous = $user->{$column};
        $user->forceFill([$column => $path])->save();

        // Remove the old file only once the new one is safely recorded.
        if ($previous !== null) {
            try {
                $disk->delete($previous);
            } catch (\Throwable) {
                // An orphaned file is a cleanup job, not a failed request.
            }
        }

        return $this->ok([
            'slot' => $slot,
            'user' => new UserResource($user->fresh()),
        ], 'Photo updated.');
    }

    /**
     * DELETE /api/v1/profile/avatar?slot=primary|alternate
     */
    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        $slot = $request->query('slot', 'primary');
        $column = $slot === 'alternate' ? 'alternate_avatar_path' : 'avatar_path';

        if ($user->{$column} !== null) {
            try {
                Storage::disk(config('filesystems.default'))->delete($user->{$column});
            } catch (\Throwable) {
                // Same as above: losing the file is not worth failing on.
            }

            $user->forceFill([$column => null]);

            // With no decoy left there is nothing to show strangers.
            if ($slot === 'alternate') {
                $user->use_alternate_avatar = false;
            }

            $user->save();
        }

        return $this->ok(new UserResource($user->fresh()), 'Photo removed.');
    }

    /*
    |--------------------------------------------------------------------------
    | Safety
    |--------------------------------------------------------------------------
    */

    /**
     * GET /api/v1/safety/status
     *
     * The whole home-screen safety picture in one payload: the status card,
     * the check-in card, the streak and the last seven days. One request
     * rather than three, because they are one fact and must not disagree.
     */
    public function safetyStatus(Request $request): JsonResponse
    {
        return $this->ok($this->safety->status($request->user()), 'OK');
    }

    /**
     * POST /api/v1/safety/check-in
     *
     * Idempotent for the user's local day. A second tap returns 200 with the
     * existing check-in rather than an error — a duplicate request, whether
     * from an impatient finger or a retry after a dropped response, is not a
     * failure the user should be shown.
     *
     * Returns the same payload as safetyStatus so the client replaces both
     * cards from this one response instead of re-fetching.
     */
    public function checkIn(CheckInRequest $request): JsonResponse
    {
        $result = $this->safety->checkIn(
            $request->user(),
            $request->checkInData(),
            $request,
        );

        return $this->ok(
            $result['status'],
            $result['created']
                ? "You're marked safe for today."
                : "You've already checked in today.",
        );
    }

    /**
     * GET /api/v1/safety/check-ins?days=30
     *
     * History for the streak view. Capped at a year so a bad client cannot
     * ask for everything.
     */
    public function checkInHistory(Request $request): JsonResponse
    {
        $days = (int) $request->integer('days', 30);
        $days = max(1, min(365, $days));

        return $this->ok($this->safety->history($request->user(), $days), 'OK');
    }

    /*
    |--------------------------------------------------------------------------
    | People
    |--------------------------------------------------------------------------
    */

    /**
     * GET /api/v1/users/search?q=...
     *
     * Live search, called on each keystroke after a client-side debounce, so
     * it gets a loose throttle. Name and username match on a prefix; a phone
     * number matches only in full — see RelationshipService::search for why.
     *
     * Every result carries its relationship state, so the list can render the
     * right button without a second round trip per row.
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $term = (string) $request->query('q', '');

        return $this->ok(
            $this->relationships->search($request->user(), $term),
            'OK',
        );
    }

    /**
     * GET /api/v1/users/{uuid}
     *
     * A profile as the caller is allowed to see it. Somebody who is not an
     * accepted follower gets name, username, avatar and counts only, plus the
     * alternate avatar if that person set one.
     */
    public function showUser(Request $request, string $uuid): JsonResponse
    {
        return $this->ok(
            $this->relationships->profile($request->user(), $this->findUser($uuid)),
            'OK',
        );
    }

    /**
     * POST /api/v1/users/{uuid}/follow
     */
    public function followUser(Request $request, string $uuid): JsonResponse
    {
        $target = $this->findUser($uuid);
        $follow = $this->relationships->follow($request->user(), $target);

        return $this->ok(
            $this->relationships->profile($request->user()->fresh(), $target->fresh()),
            $follow->isAccepted() ? 'You are following them.' : 'Request sent.',
        );
    }

    /**
     * DELETE /api/v1/users/{uuid}/follow
     *
     * Unfollow, or withdraw a request that has not been answered.
     */
    public function unfollowUser(Request $request, string $uuid): JsonResponse
    {
        $target = $this->findUser($uuid);
        $this->relationships->unfollow($request->user(), $target);

        return $this->ok(
            $this->relationships->profile($request->user()->fresh(), $target->fresh()),
            'Removed.',
        );
    }

    /**
     * DELETE /api/v1/users/{uuid}/follower
     *
     * Remove somebody who follows me.
     */
    public function removeFollower(Request $request, string $uuid): JsonResponse
    {
        $target = $this->findUser($uuid);
        $this->relationships->removeFollower($request->user(), $target);

        return $this->ok(
            $this->relationships->profile($request->user()->fresh(), $target->fresh()),
            'Follower removed.',
        );
    }

    /**
     * POST /api/v1/follow-requests/{uuid}/respond   { "accept": true }
     */
    public function respondToFollowRequest(RespondRequest $request, string $uuid): JsonResponse
    {
        $follow = $this->relationships->respondToFollow(
            $request->user(),
            $uuid,
            $request->accepts(),
        );

        return $this->ok(
            $this->relationships->profile($request->user()->fresh(), $follow->follower),
            $request->accepts() ? 'Request accepted.' : 'Request declined.',
        );
    }

    /**
     * GET /api/v1/users/{uuid}/followers
     */
    public function userFollowers(Request $request, string $uuid): JsonResponse
    {
        return $this->ok(
            $this->relationships->followers($request->user(), $this->findUser($uuid)),
            'OK',
        );
    }

    /**
     * GET /api/v1/users/{uuid}/following
     */
    public function userFollowing(Request $request, string $uuid): JsonResponse
    {
        return $this->ok(
            $this->relationships->following($request->user(), $this->findUser($uuid)),
            'OK',
        );
    }

    /**
     * GET /api/v1/follow-requests
     */
    public function followRequests(Request $request): JsonResponse
    {
        return $this->ok(
            $this->relationships->pendingFollowRequests($request->user()),
            'OK',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Family
    |--------------------------------------------------------------------------
    */

    /**
     * GET /api/v1/family
     *
     * Everyone in the caller's circle, from both ends of the table. Drives the
     * home screen's family strip.
     */
    public function family(Request $request): JsonResponse
    {
        return $this->ok($this->relationships->family($request->user()), 'OK');
    }

    /**
     * POST /api/v1/users/{uuid}/family   { "relation": "mother" }
     *
     * Only works once they have accepted your follow. Family membership is
     * what will later carry location and SOS visibility, so it needs its own
     * consent rather than riding along on a follow.
     */
    public function inviteToFamily(FamilyInviteRequest $request, string $uuid): JsonResponse
    {
        $target = $this->findUser($uuid);

        $this->relationships->inviteToFamily(
            $request->user(),
            $target,
            $request->relation(),
        );

        return $this->ok(
            $this->relationships->profile($request->user()->fresh(), $target->fresh()),
            'Invite sent.',
        );
    }

    /**
     * POST /api/v1/family-invites/{uuid}/respond   { "accept": true }
     */
    public function respondToFamilyInvite(RespondRequest $request, string $uuid): JsonResponse
    {
        $family = $this->relationships->respondToFamily(
            $request->user(),
            $uuid,
            $request->accepts(),
            $request->input('relation'),
        );

        return $this->ok(
            $this->relationships->profile($request->user()->fresh(), $family->owner),
            $request->accepts() ? 'Added to your family.' : 'Invite declined.',
        );
    }

    /**
     * DELETE /api/v1/family/{uuid}
     *
     * Either side may end a family link.
     */
    public function removeFamilyMember(Request $request, string $uuid): JsonResponse
    {
        $this->relationships->removeFamily($request->user(), $uuid);

        return $this->ok($this->relationships->family($request->user()), 'Removed.');
    }

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * GET /api/v1/notifications?page=1
     *
     * Each entry's action state is resolved from the request it points at, not
     * stored — so a request accepted from a profile screen never leaves a
     * stale Accept button here.
     */
    public function notifications(Request $request): JsonResponse
    {
        return $this->ok(
            $this->notifier->feed(
                $request->user(),
                max(1, (int) $request->integer('page', 1)),
                (int) $request->integer('per_page', 30),
            ),
            'OK',
        );
    }

    /**
     * GET /api/v1/notifications/unread-count
     *
     * Polled far more often than the feed is opened, so it stays a single
     * indexed count rather than loading rows.
     */
    public function unreadNotificationCount(Request $request): JsonResponse
    {
        return $this->ok(
            ['unread' => $this->notifier->unreadCount($request->user())],
            'OK',
        );
    }

    /**
     * POST /api/v1/notifications/{uuid}/read
     */
    public function readNotification(Request $request, string $uuid): JsonResponse
    {
        $this->notifier->markRead($request->user(), $uuid);

        return $this->ok(
            ['unread' => $this->notifier->unreadCount($request->user())],
            'OK',
        );
    }

    /**
     * POST /api/v1/notifications/read-all
     */
    public function readAllNotifications(Request $request): JsonResponse
    {
        $this->notifier->markAllRead($request->user());

        return $this->ok(['unread' => 0], 'All caught up.');
    }

    /**
     * Resolve a public user id, or 404.
     *
     * Only ever accepts a uuid — the auto-increment key is not addressable
     * from outside, so user ids stay unguessable.
     */
    private function findUser(string $uuid): User
    {
        $user = User::where('uuid', $uuid)->first();

        abort_if($user === null, 404, 'That account does not exist.');

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    */

    /**
     * GET /api/v1/media/avatar/{uuid}/{slot}   (signed)
     *
     * Streams a stored avatar.
     *
     * Avatars live under storage/app/private, which nginx does not serve, and
     * that is deliberate — a safety app should not put members' faces behind
     * guessable public URLs. The signature on this route is the credential,
     * not the bearer token, so the link can be handed straight to an <img>
     * tag or Flutter's Image.network without attaching headers.
     *
     * Links expire after User::MEDIA_LINK_HOURS. Clients must re-read them
     * from /me rather than storing them.
     */
    public function streamAvatar(Request $request, string $uuid, string $slot): StreamedResponse
    {
        $user = User::where('uuid', $uuid)->first();

        abort_if($user === null, 404);

        $path = $slot === 'alternate'
            ? $user->alternate_avatar_path
            : $user->avatar_path;

        abort_if($path === null, 404);

        $disk = Storage::disk(config('filesystems.default'));

        abort_unless($disk->exists($path), 404);

        // Private: the link is per-user and time-limited, so a shared cache
        // must not hold on to the bytes.
        return $disk->response($path, null, [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Record the sign-in, register the device and mint a token.
     *
     * @return array<string, mixed>
     */
    private function startSession(User $user, Request $request): array
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
                // Present only while no SMS provider is connected, so the app
                // can show the code on screen during development.
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
