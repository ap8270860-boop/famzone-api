<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\Chat\ConversationPinned;
use App\Events\Chat\MessageReacted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\SendOtpRequest;
use App\Http\Requests\Api\V1\Auth\VerifyOtpRequest;
use App\Http\Requests\Api\V1\Chat\ForwardRequest;
use App\Http\Requests\Api\V1\Chat\PinRequest;
use App\Http\Requests\Api\V1\Chat\ReactRequest;
use App\Http\Requests\Api\V1\Chat\ReceiptRequest;
use App\Http\Requests\Api\V1\Chat\SendMessageRequest;
use App\Http\Requests\Api\V1\Chat\StartConversationRequest;
use App\Http\Requests\Api\V1\Chat\UploadRequest;
use App\Http\Requests\Api\V1\Posts\CreatePostRequest;
use App\Http\Requests\Api\V1\Profile\UpdateAvatarRequest;
use App\Http\Requests\Api\V1\Safety\CheckInRequest;
use App\Http\Requests\Api\V1\Social\BlockRequest;
use App\Http\Requests\Api\V1\Social\FamilyInviteRequest;
use App\Http\Requests\Api\V1\Social\RespondRequest;
use App\Http\Requests\Api\V1\Profile\UpdatePasswordRequest;
use App\Http\Requests\Api\V1\Profile\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\OtpCode;
use App\Models\Post;
use App\Models\User;
use App\Services\Chat\AttachmentService;
use App\Services\Chat\ChatService;
use App\Services\Chat\MessageActionService;
use App\Services\Chat\PresenceService;
use App\Services\Chat\ReactionService;
use App\Services\Chat\ReceiptService;
use App\Services\Chat\ThreadSettingsService;
use App\Services\Otp\Exceptions\OtpException;
use App\Services\Otp\OtpService;
use App\Services\Posts\PostService;
use App\Services\Profile\UsernameChecker;
use App\Services\Safety\SafetyService;
use App\Services\Social\BlockService;
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
        private readonly BlockService $blocks,
        private readonly PostService $posts,
        private readonly ChatService $chat,
        private readonly ReceiptService $receipts,
        private readonly PresenceService $presence,
        private readonly AttachmentService $attachments,
        private readonly ReactionService $reactions,
        private readonly MessageActionService $messageActions,
        private readonly ThreadSettingsService $threads,
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
                    'user_type' => $data['user_type'] ?? User::TYPE_ADULT,
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

        // Opening a private account accepts everyone already waiting —
        // otherwise those requests would sit unanswered while new followers
        // walk straight in.
        $opened = array_key_exists('is_private', $data)
            && $user->is_private
            && $data['is_private'] === false;

        $user->fill($data)->save();

        $accepted = $opened
            ? $this->relationships->acceptAllPendingFollows($user->fresh())
            : 0;

        return $this->ok(
            new UserResource($user->fresh()),
            $accepted > 0
                ? 'Profile updated. '.$accepted.' pending '
                    .($accepted === 1 ? 'request was' : 'requests were').' accepted.'
                : 'Profile updated.',
        );
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
            $request->wasAccepted(),
        );

        return $this->ok(
            $this->relationships->profile($request->user()->fresh(), $follow->follower),
            $request->wasAccepted() ? 'Request accepted.' : 'Request declined.',
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
            $request->wasAccepted(),
            $request->input('relation'),
        );

        return $this->ok(
            $this->relationships->profile($request->user()->fresh(), $family->owner),
            $request->wasAccepted() ? 'Added to your family.' : 'Invite declined.',
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
    | Blocking
    |--------------------------------------------------------------------------
    */

    /**
     * POST /api/v1/users/{uuid}/block
     *
     * Severs follows both ways, ends any family link, and clears the
     * notifications each holds about the other — then records the block.
     * All of it in one transaction, because half a block is worse than none.
     *
     * The blocked person is never told.
     */
    public function blockUser(BlockRequest $request, string $uuid): JsonResponse
    {
        $target = $this->findUser($uuid);

        $this->blocks->block($request->user(), $target, $request->reason());

        return $this->ok(
            $this->relationships->profile($request->user()->fresh(), $target->fresh()),
            $target->name.' has been blocked.',
        );
    }

    /**
     * DELETE /api/v1/users/{uuid}/block
     *
     * Lifts the block. Does not restore the follows or family link it removed
     * — those have to be asked for again.
     */
    public function unblockUser(Request $request, string $uuid): JsonResponse
    {
        $target = $this->findUser($uuid);

        $this->blocks->unblock($request->user(), $target);

        return $this->ok(
            $this->relationships->profile($request->user()->fresh(), $target->fresh()),
            $target->name.' has been unblocked.',
        );
    }

    /**
     * GET /api/v1/blocks
     *
     * The only way back: a blocked account is hidden from search, so without
     * this list a block would be permanent by accident.
     */
    public function blockedAccounts(Request $request): JsonResponse
    {
        return $this->ok($this->blocks->blockedList($request->user()), 'OK');
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
    | Posts
    |--------------------------------------------------------------------------
    */

    /**
     * POST /api/v1/posts   (multipart: image, caption, tagged[])
     *
     * Publishes a photo. The client crops to a square before uploading; the
     * real dimensions are recorded either way so a grid can reserve the right
     * box before the bytes arrive.
     *
     * Tagging is limited to people the author follows or who follow them —
     * otherwise a post is a way to attach your name to a stranger's photo.
     */
    public function createPost(CreatePostRequest $request): JsonResponse
    {
        $post = $this->posts->create(
            $request->user(),
            $request->file('image'),
            $request->input('caption'),
            $request->taggedUuids(),
        );

        return $this->created(
            $this->posts->show($request->user(), $post),
            'Posted.',
        );
    }

    /**
     * GET /api/v1/users/{uuid}/posts?page=1
     *
     * One person's grid, newest first.
     *
     * Answers `can_view: false` with an empty list rather than a 403 when the
     * account is private and the caller is not an accepted follower — the
     * profile screen needs to draw a "private account" panel, and an error
     * would make that look like a failure instead of a state.
     */
    public function userPosts(Request $request, string $uuid): JsonResponse
    {
        return $this->ok(
            $this->posts->forUser(
                $request->user(),
                $this->findUser($uuid),
                max(1, (int) $request->integer('page', 1)),
                (int) $request->integer('per_page', 24),
            ),
            'OK',
        );
    }

    /**
     * GET /api/v1/users/{uuid}/tagged-posts?page=1
     *
     * Posts this person has been tagged in, filtered by whether the
     * caller may see each post's own author.
     */
    public function userTaggedPosts(Request $request, string $uuid): JsonResponse
    {
        return $this->ok(
            $this->posts->taggedIn(
                $request->user(),
                $this->findUser($uuid),
                max(1, (int) $request->integer('page', 1)),
                (int) $request->integer('per_page', 24),
            ),
            'OK',
        );
    }

    /**
     * GET /api/v1/posts/{uuid}
     */
    public function showPost(Request $request, string $uuid): JsonResponse
    {
        $payload = $this->posts->show($request->user(), $this->findPost($uuid));

        abort_if($payload === null, 404, 'That post is not available.');

        return $this->ok($payload, 'OK');
    }

    /**
     * DELETE /api/v1/posts/{uuid}
     */
    public function deletePost(Request $request, string $uuid): JsonResponse
    {
        $this->posts->delete($request->user(), $this->findPost($uuid));

        return $this->ok(null, 'Post deleted.');
    }

    /**
     * POST /api/v1/posts/{uuid}/like
     *
     * Idempotent: liking twice is not an error, because the caller asked for
     * a state rather than an increment.
     */
    public function likePost(Request $request, string $uuid): JsonResponse
    {
        return $this->ok(
            $this->posts->toggleLike($request->user(), $this->findPost($uuid), true),
            'Liked.',
        );
    }

    /**
     * DELETE /api/v1/posts/{uuid}/like
     */
    public function unlikePost(Request $request, string $uuid): JsonResponse
    {
        return $this->ok(
            $this->posts->toggleLike($request->user(), $this->findPost($uuid), false),
            'Removed.',
        );
    }

    /**
     * GET /api/v1/posts/{uuid}/likes
     */
    public function postLikes(Request $request, string $uuid): JsonResponse
    {
        return $this->ok(
            $this->posts->likes($request->user(), $this->findPost($uuid)),
            'OK',
        );
    }

    /**
     * GET /api/v1/media/post/{uuid}   (signed)
     *
     * Streams a post image. Same reasoning as the avatar route: posts live on
     * a private disk, and the signature in the query string is the credential,
     * so the link works inside a plain <img> tag.
     */
    public function streamPostImage(Request $request, string $uuid): StreamedResponse
    {
        $post = Post::where('uuid', $uuid)->first();

        abort_if($post === null, 404);

        $disk = Storage::disk(config('filesystems.default'));

        abort_unless($disk->exists($post->image_path), 404);

        return $disk->response($post->image_path, null, [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Resolve a post by its public id, or 404.
     */
    private function findPost(string $uuid): Post
    {
        $post = Post::where('uuid', $uuid)->first();

        abort_if($post === null, 404, 'That post is not available.');

        return $post;
    }

    /*
    |--------------------------------------------------------------------------
    | Chat
    |--------------------------------------------------------------------------
    |
    | No broadcasting yet. These endpoints are the whole of the feature in
    | this phase, on purpose: if the conversation works over plain HTTP with
    | a pull to refresh, then the websocket that follows is an accelerator
    | rather than a load-bearing part, and a dropped socket costs latency
    | instead of messages.
    |
    */

    /**
     * GET /api/v1/conversations?state=accepted|pending&page=1
     *
     * The inbox. `state=pending` is the Requests tab — the same query, the
     * same shape, a different set of threads.
     */
    public function conversations(Request $request): JsonResponse
    {
        $state = $request->string('state', ConversationParticipant::STATE_ACCEPTED)->toString();

        abort_unless(
            in_array($state, [
                ConversationParticipant::STATE_ACCEPTED,
                ConversationParticipant::STATE_PENDING,
            ], true),
            422,
            'Unknown conversation state.',
        );

        return $this->ok(
            $this->chat->inbox(
                $request->user(),
                $state,
                max(1, (int) $request->integer('page', 1)),
                (int) $request->integer('per_page', ChatService::INBOX_PER_PAGE),
                // The same list, filtered the other way. Archived threads
                // are excluded from the ordinary inbox by default.
                $request->boolean('archived'),
            ),
            'OK',
        );
    }

    /**
     * GET /api/v1/conversations/unread-count
     *
     * Polled on cold start and whenever the app returns to the foreground,
     * so it is deliberately one grouped query over an indexed column.
     */
    public function chatUnreadCount(Request $request): JsonResponse
    {
        return $this->ok($this->chat->unreadSummary($request->user()), 'OK');
    }

    /**
     * POST /api/v1/conversations   {user_id}
     *
     * Opens the thread with somebody, creating it only if there is not one
     * already. Safe to call every time the Message button is tapped — the
     * pair key makes a second call return the first call's thread.
     */
    public function startConversation(StartConversationRequest $request): JsonResponse
    {
        $me = $request->user();

        $conversation = $this->chat->findOrCreateDirect(
            $me,
            $this->findUser($request->targetUuid()),
        );

        return $this->ok(
            $this->chat->presentConversation(
                $me,
                $this->chat->findConversation($me, $conversation->uuid),
            ),
            'OK',
        );
    }

    /**
     * GET /api/v1/conversations/{uuid}
     */
    public function showConversation(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        return $this->ok(
            $this->chat->presentConversation($me, $this->chat->findConversation($me, $uuid)),
            'OK',
        );
    }

    /**
     * GET /api/v1/conversations/{uuid}/messages?before=&after=&limit=
     *
     * `before` walks back through history as the user scrolls up. `after`
     * fills the gap left by a dropped connection — the client passes the
     * newest sequence number it already holds and receives everything since.
     *
     * Cursors are per-conversation sequence numbers starting at 1, so they
     * order the thread without exposing anything about the database.
     */
    public function conversationMessages(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        return $this->ok(
            $this->chat->history(
                $me,
                $this->chat->findConversation($me, $uuid),
                $request->filled('before') ? (int) $request->integer('before') : null,
                $request->filled('after') ? (int) $request->integer('after') : null,
                (int) $request->integer('limit', Message::PER_PAGE),
            ),
            'OK',
        );
    }

    /**
     * POST /api/v1/conversations/{uuid}/messages   {client_uuid, body}
     *
     * Idempotent on client_uuid: a retry after a timeout returns the original
     * message rather than creating a second one, and returns it as a success,
     * because from the sender's point of view the message was sent.
     */
    public function sendMessage(SendMessageRequest $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        $result = $this->chat->send(
            $me,
            $this->chat->findConversation($me, $uuid),
            $request->payload(),
        );

        // 200 on a replay, 201 on a genuinely new message. The client keys off
        // client_uuid either way, so the distinction is for logs and for
        // anyone reading the network tab.
        return $result['replayed']
            ? $this->ok($result['message'], 'Already sent.')
            : $this->created($result['message'], 'Sent.');
    }

    /**
     * POST /api/v1/conversations/{uuid}/read   {message_id}
     *
     * Moves the read watermark, never backwards. Safe to fire on every scroll
     * and every arriving message.
     */
    public function markConversationRead(ReceiptRequest $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        return $this->ok(
            $this->receipts->markRead(
                $me,
                $this->chat->findConversation($me, $uuid),
                $request->messageUuid(),
            ),
            'OK',
        );
    }

    /**
     * POST /api/v1/conversations/{uuid}/delivered   {message_id}
     */
    public function markConversationDelivered(ReceiptRequest $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        return $this->ok(
            $this->receipts->markDelivered(
                $me,
                $this->chat->findConversation($me, $uuid),
                $request->messageUuid(),
            ),
            'OK',
        );
    }

    /**
     * POST /api/v1/conversations/{uuid}/accept
     */
    public function acceptConversation(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        return $this->ok(
            $this->chat->accept($me, $this->chat->findConversation($me, $uuid)),
            'Request accepted.',
        );
    }

    /**
     * DELETE /api/v1/conversations/{uuid}
     *
     * Leaves the thread, or declines a request. The membership row survives
     * so a later message reopens the same conversation rather than starting a
     * second one beside it.
     */
    public function leaveConversation(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        $this->chat->leave($me, $this->chat->findConversation($me, $uuid));

        return $this->ok(null, 'Conversation removed.');
    }

    /**
     * DELETE /api/v1/messages/{uuid}
     *
     * Delete for everyone. Soft, so the other person's client can show a
     * tombstone instead of losing a line out of the middle of the thread.
     */
    public function deleteMessage(Request $request, string $uuid): JsonResponse
    {
        $this->chat->deleteMessage($request->user(), $this->findMessage($uuid));

        return $this->ok(null, 'Message deleted.');
    }

    /**
     * POST /api/v1/presence/ping
     *
     * The heartbeat behind "online" and "last seen". Answers with the
     * interval the client should use, so the window can be widened later
     * without shipping a new build.
     */
    public function presencePing(Request $request): JsonResponse
    {
        return $this->ok($this->presence->ping($request->user()), 'OK');
    }

    /**
     * POST /api/v1/uploads   (multipart: file, type)
     *
     * Step one of sending a file. Answers with an id; step two is a normal
     * send carrying `upload_id`.
     *
     * Two steps rather than one fat request: a 25 MB file has no business
     * holding a chat request open, and — more to the point — the message row
     * is not written until the bytes have landed. A message broadcast ahead
     * of its file shows every recipient a broken attachment.
     *
     * An upload that is never sent is harmless; `chat:prune-uploads` sweeps
     * it up after a day.
     */
    public function uploadAttachment(UploadRequest $request): JsonResponse
    {
        $attachment = $this->attachments->upload(
            $request->user(),
            $request->file('file'),
            (string) $request->input('type'),
            $request->durationMs(),
            $request->waveform(),
        );

        return $this->created(
            $this->attachments->present($attachment),
            'Uploaded.',
        );
    }

    /**
     * GET /api/v1/media/chat/{uuid}   (signed)
     *
     * Streams an attachment. Not behind auth:sanctum on purpose — the
     * signature is the credential, which is what lets the URL go straight
     * into an <img> tag.
     */
    public function streamAttachment(Request $request, string $uuid): StreamedResponse
    {
        $attachment = MessageAttachment::where('uuid', $uuid)->first();

        abort_if($attachment === null, 404);

        $disk = Storage::disk($attachment->disk ?: config('filesystems.default'));

        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response($attachment->path, $attachment->original_name, [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * POST /api/v1/messages/{uuid}/react   {emoji}
     *
     * Add, change or remove a reaction. One endpoint for all three, because
     * the unique index means it is one row either way — `emoji: null` takes
     * yours off, and sending the same emoji twice does the same thing.
     *
     * Answers with the whole message so the caller can repaint the bubble
     * from one response, and broadcasts the same set to the other person.
     */
    public function reactToMessage(ReactRequest $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $message = $this->findMessage($uuid);

        // Membership check. Without it anybody holding a message id could
        // react into a conversation they have never been part of.
        $conversation = $this->chat->findConversation(
            $me,
            $message->conversation->uuid,
        );

        abort_if($conversation->id !== $message->conversation_id, 404);

        $updated = $this->reactions->react($me, $message, $request->emoji());

        MessageReacted::dispatch($updated);

        return $this->ok(
            $this->chat->presentMessage(
                $updated->loadMissing(['sender:id,uuid', 'attachment', 'replyTo.sender:id,uuid']),
            ),
            'OK',
        );
    }

    /**
     * POST /api/v1/conversations/{uuid}/pin-chat
     *
     * Pin the thread to the top of my own inbox. Toggles, and is invisible
     * to the other person — this is not the shared pinned message.
     */
    public function pinConversation(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        $pinned = $this->threads->togglePin(
            $me,
            $this->chat->findConversation($me, $uuid),
        );

        return $this->ok(
            ['pinned' => $pinned],
            $pinned ? 'Pinned to top.' : 'Unpinned.',
        );
    }

    /**
     * POST /api/v1/conversations/{uuid}/archive
     *
     * Put the thread away, or bring it back. Toggles, and is invisible to
     * the other person — archiving is about which of my lists it appears in,
     * nothing more.
     */
    public function archiveConversation(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        $archived = $this->threads->toggleArchive(
            $me,
            $this->chat->findConversation($me, $uuid),
        );

        return $this->ok(
            ['archived' => $archived],
            $archived ? 'Archived.' : 'Moved back to your chats.',
        );
    }

    /**
     * POST /api/v1/conversations/{uuid}/mute   {muted, hours?}
     *
     * Notifications only. Messages still arrive and the thread still counts
     * as unread; muting is about whether the phone makes a noise.
     */
    public function muteConversation(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $conversation = $this->chat->findConversation($me, $uuid);

        $muted = $request->boolean('muted', true);
        $hours = $request->input('hours');

        $until = $this->threads->mute(
            $me,
            $conversation,
            $muted,
            is_numeric($hours) ? (int) $hours : null,
        );

        return $this->ok(
            ['muted' => $muted, 'muted_until' => $until?->toIso8601String()],
            $muted ? 'Muted.' : 'Unmuted.',
        );
    }

    /**
     * POST /api/v1/conversations/{uuid}/unread
     *
     * Make it look unread again. A flag of my own — the read watermark does
     * not move, so their ticks stay exactly as they were.
     */
    public function markConversationUnread(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        $this->threads->markUnread(
            $me,
            $this->chat->findConversation($me, $uuid),
        );

        return $this->ok(['marked_unread' => true], 'Marked as unread.');
    }

    /**
     * POST /api/v1/conversations/{uuid}/clear
     *
     * Empty the thread on my side. Every message hidden for me, exactly as
     * if I had deleted each one for myself; the thread itself stays in the
     * list and the other person keeps everything.
     */
    public function clearConversation(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        $this->threads->clear(
            $me,
            $this->chat->findConversation($me, $uuid),
        );

        return $this->ok(null, 'Chat cleared.');
    }

    /**
     * GET /api/v1/messages/{uuid}/info
     *
     * When it reached them, and when they read it. Only for messages you
     * wrote yourself.
     */
    public function messageInfo(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $message = $this->findMessage($uuid);

        // Membership check — a message id alone must not be enough.
        $conversation = $this->chat->findConversation($me, $message->conversation->uuid);

        return $this->ok($this->receipts->info($me, $conversation, $message), 'OK');
    }

    /**
     * POST /api/v1/messages/{uuid}/hide
     *
     * Delete for me. Works on anybody's message, unlike delete for everyone
     * — removing something from your own screen needs no permission from the
     * person who wrote it.
     */
    public function hideMessage(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $message = $this->findMessage($uuid);

        // Membership check — a message id alone must not be enough.
        $this->chat->findConversation($me, $message->conversation->uuid);

        $this->messageActions->hideForMe($me, $message);

        return $this->ok(null, 'Deleted for you.');
    }

    /**
     * POST /api/v1/messages/{uuid}/star
     *
     * Toggles. Private to the caller: nothing is broadcast, and the other
     * person in the thread has no way to learn you kept something.
     */
    public function starMessage(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $message = $this->findMessage($uuid);

        // Membership check — a message id alone must not be enough.
        $this->chat->findConversation($me, $message->conversation->uuid);

        $starred = $this->messageActions->toggleStar($me, $message);

        return $this->ok(
            ['starred' => $starred],
            $starred ? 'Starred.' : 'Removed from starred.',
        );
    }

    /**
     * GET /api/v1/starred-messages?page=1
     *
     * Only from threads the caller is still in — leaving a conversation, or
     * being blocked out of one, takes its messages out of here too.
     */
    public function starredMessages(Request $request): JsonResponse
    {
        return $this->ok(
            $this->messageActions->starred(
                $request->user(),
                max(1, (int) $request->integer('page', 1)),
            ),
            'OK',
        );
    }

    /**
     * POST /api/v1/messages/{uuid}/forward   {conversation_ids: []}
     *
     * A new message in each target, never a reference to the original: the
     * recipient must not gain access to the thread it came from, and a shared
     * row would mean deleting the original deletes every forward of it.
     */
    public function forwardMessage(ForwardRequest $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        $sent = $this->messageActions->forward(
            $me,
            $this->findMessage($uuid),
            $request->targets(),
        );

        // Announced after every write has committed, the same as an ordinary
        // send — each target thread gets its own message and inbox events.
        foreach ($sent as $message) {
            $this->chat->announce($message->conversation, $message);
        }

        return $this->ok(
            ['count' => count($sent)],
            count($sent) === 1 ? 'Forwarded.' : 'Forwarded to '.count($sent).' chats.',
        );
    }

    /**
     * POST /api/v1/conversations/{uuid}/pin   {message_id}
     *
     * Shared by both people, so either can set or clear it and both banners
     * move together. `message_id: null` unpins.
     */
    public function pinMessage(PinRequest $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $conversation = $this->chat->findConversation($me, $uuid);

        $messageUuid = $request->messageUuid();

        $updated = $this->messageActions->pin(
            $me,
            $conversation,
            $messageUuid === null ? null : $this->findMessage($messageUuid),
        );

        ConversationPinned::dispatch($updated);

        return $this->ok(
            $this->chat->presentConversation($me, $updated),
            $messageUuid === null ? 'Unpinned.' : 'Pinned.',
        );
    }

    private function findMessage(string $uuid): Message
    {
        $message = Message::with([
            'sender:id,uuid',
            'attachment',
            'replyTo.sender:id,uuid',
        ])->where('uuid', $uuid)->first();

        abort_if($message === null, 404, 'That message does not exist.');

        return $message;
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
