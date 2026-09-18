<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\Chat\ConversationPinned;
use App\Events\Chat\MessageReacted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\SendOtpRequest;
use App\Http\Requests\Api\V1\Auth\VerifyOtpRequest;
use App\Http\Requests\Api\V1\Chat\CreateGroupRequest;
use App\Http\Requests\Api\V1\Chat\UpdateGroupRequest;
use App\Http\Requests\Api\V1\Chat\ForwardRequest;
use App\Http\Requests\Api\V1\Chat\PinRequest;
use App\Http\Requests\Api\V1\Chat\ReactRequest;
use App\Http\Requests\Api\V1\Chat\ReceiptRequest;
use App\Http\Requests\Api\V1\Chat\SendMessageRequest;
use App\Http\Requests\Api\V1\Chat\StartConversationRequest;
use App\Http\Requests\Api\V1\Chat\UploadRequest;
use App\Http\Requests\Api\V1\Location\PinLocationRequest;
use App\Http\Requests\Api\V1\Location\PingLocationRequest;
use App\Http\Requests\Api\V1\Location\SavePlaceRequest;
use App\Http\Requests\Api\V1\Location\ShareLocationRequest;
use App\Http\Requests\Api\V1\Posts\CreatePostRequest;
use App\Http\Requests\Api\V1\Profile\UpdateAvatarRequest;
use App\Http\Requests\Api\V1\Reminders\SaveReminderRequest;
use App\Http\Requests\Api\V1\Safety\CheckInRequest;
use App\Http\Requests\Api\V1\Safety\NearbyPlacesRequest;
use App\Http\Requests\Api\V1\Safety\SaveCheckInContactsRequest;
use App\Http\Requests\Api\V1\Safety\StartSosRequest;
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
use App\Services\Chat\GroupService;
use App\Services\Chat\MessageActionService;
use App\Services\Chat\PresenceService;
use App\Services\Chat\ReactionService;
use App\Services\Chat\ReceiptService;
use App\Services\Chat\ThreadSettingsService;
use App\Services\Location\FamilyPlaceService;
use App\Services\Location\LocationHistoryService;
use App\Services\Location\LocationService;
use App\Services\Location\RoutingService;
use App\Services\Otp\Exceptions\OtpException;
use App\Services\Otp\OtpService;
use App\Services\Posts\PostService;
use App\Services\Profile\UsernameChecker;
use App\Services\Reminders\ReminderService;
use App\Services\Safety\CheckInEscalationService;
use App\Services\Safety\EmergencyDirectory;
use App\Services\Safety\PlacesService;
use App\Services\Safety\SafetyService;
use App\Services\Safety\SosService;
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

        // The ordered "tell these people" chain behind a check-in. Separate
        // from SafetyService because it is a different lifetime: a check-in is
        // one instant, a chain runs for hours afterwards.
        private readonly CheckInEscalationService $escalations,

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
        private readonly GroupService $groups,
        private readonly LocationService $locations,

        // Family geofences. Not to be confused with $places below, which is
        // the Google Places proxy — see FamilyPlaceService's own note.
        private readonly FamilyPlaceService $familyPlaces,
        private readonly LocationHistoryService $history,

        // Google's Routes API, proxied. Same key and same rule as $places
        // below: it is IP-restricted to this box and never ships in a build.
        private readonly RoutingService $routing,

        // Reminders. Nothing in this service makes a phone ring — the device
        // reads these rows and registers real OS alarms against them.
        private readonly ReminderService $reminders,

        private readonly SosService $sos,
        private readonly PlacesService $places,
        private readonly EmergencyDirectory $directory,
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
     *
     * May also carry `contacts` — the ordered list of family to notify. Sent
     * on the first check-in, where choosing the list and checking in are one
     * gesture, and on any later check-in where the user edited the order in
     * the same sheet. Omitting it leaves the saved list alone, which is what
     * every ordinary day looks like.
     */
    public function checkIn(CheckInRequest $request): JsonResponse
    {
        $result = $this->safety->checkIn(
            $request->user(),
            $request->checkInData(),
            $request,
            $request->contactOrder(),
        );

        $chain = $result['status']['check_in']['chain'] ?? null;
        $notified = $chain['total_steps'] ?? 0;

        return $this->ok(
            $result['status'],
            $result['created']
                ? ($notified > 0
                    // Named rather than counted where it is one person: "1
                    // person will be told" is a sentence no human writes.
                    ? "You're marked safe. Letting your family know."
                    : "You're marked safe for today.")
                : "You've already checked in today.",
        );
    }

    /**
     * GET /api/v1/safety/check-in/contacts
     *
     * The picker, in one request: the ordered list as it stands, everybody who
     * could be added, and the limits.
     */
    public function checkInContacts(Request $request): JsonResponse
    {
        return $this->ok($this->escalations->contacts($request->user()), 'OK');
    }

    /**
     * PUT /api/v1/safety/check-in/contacts
     *
     * Replaces the list wholesale — the array's order is the notification
     * order. See SaveCheckInContactsRequest for why there is no position
     * field, and CheckInEscalationService::saveContacts for why unknown ids
     * are dropped rather than rejected.
     *
     * PUT rather than POST because it is idempotent by construction: sending
     * the same list twice leaves the same list.
     */
    public function saveCheckInContacts(SaveCheckInContactsRequest $request): JsonResponse
    {
        $contacts = $this->escalations->saveContacts(
            $request->user(),
            $request->contactOrder(),
        );

        return $this->ok(
            $contacts,
            $contacts['configured']
                ? 'Saved. Your family will be told in this order.'
                : 'Saved. Your check-ins stay private.',
        );
    }

    /**
     * GET /api/v1/safety/check-in/requests
     *
     * Check-ins waiting on *this* user's confirmation.
     *
     * Separate from the notification feed on purpose. The feed is a history
     * that happens to contain some actionable rows; this is a short list of
     * things somebody is actively waiting on an answer for, and the banner
     * that appears on app resume is built from it. Asking the feed the same
     * question would mean paging through follow requests from March.
     */
    public function checkInRequests(Request $request): JsonResponse
    {
        return $this->ok($this->escalations->awaiting($request->user()), 'OK');
    }

    /**
     * POST /api/v1/safety/check-in/requests/{uuid}/respond
     *
     * Accept — "I know you are safe" — stops the chain and nobody further down
     * the list is ever told. Decline hands it straight to the next person
     * rather than making them wait out the timer.
     *
     * Never fails for being late. A step that has already closed returns 200
     * with a sentence explaining what happened to it, because by the time a
     * slow phone's tap arrives the request may genuinely have moved on, and
     * "Vinod already confirmed" is the true and useful answer — not an error.
     */
    public function respondToCheckIn(RespondRequest $request, string $uuid): JsonResponse
    {
        $result = $this->escalations->respond(
            $request->user(),
            $uuid,
            $request->wasAccepted(),
        );

        return $this->ok(
            [
                'changed' => $result['changed'],
                'chain' => $result['chain'],
                'awaiting' => $this->escalations->awaiting($request->user()),
            ],
            $result['message'],
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
    | Reminders
    |--------------------------------------------------------------------------
    |
    | One rule runs through all of these: nothing here makes a phone ring.
    | The device reads these rows and registers real OS alarms against them,
    | which is why a reminder fires on a plane, in a lift, with the app
    | force-closed and this server switched off.
    |
    */

    /**
     * GET /api/v1/reminders/catalogue
     *
     * The twelve categories and their presets. Seeded data, identical for
     * every account, cached for an hour — which is why it is a separate
     * endpoint from the reminders themselves rather than riding along with
     * them on every open.
     */
    public function reminderCatalogue(): JsonResponse
    {
        return $this->ok($this->reminders->catalogue(), 'OK');
    }

    /**
     * GET /api/v1/reminders
     *
     * Mine to do, what I set for other people, today's list, and the concrete
     * moments this phone should schedule — in one response, because the four
     * have to agree with each other. A list that disagrees with the schedule
     * is a reminder that shows on screen and never rings.
     */
    public function reminders(Request $request): JsonResponse
    {
        return $this->ok($this->reminders->overview($request->user()), 'OK');
    }

    /**
     * GET /api/v1/reminders/schedule
     *
     * Just the alarms, for a client topping up its OS registrations without
     * wanting the rest. Cheaper than the overview and safe to call on every
     * resume.
     */
    public function reminderSchedule(Request $request): JsonResponse
    {
        return $this->ok(
            ['schedule' => $this->reminders->schedule($request->user())],
            'OK',
        );
    }

    /**
     * GET /api/v1/reminders/day?date=YYYY-MM-DD
     *
     * One day of the calendar: what was due and what came of it. Future days
     * are expanded from the rules; past days come from the settled rows, so
     * history does not change when a schedule is edited.
     */
    public function reminderDay(Request $request): JsonResponse
    {
        $date = (string) $request->query(
            'date',
            now()->toDateString(),
        );

        // Validated by parsing rather than by a rule, so a nonsense date is a
        // 422 with a sentence instead of a 500 from inside Carbon.
        abort_unless(
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date),
            422,
            'Send the date as YYYY-MM-DD.',
        );

        return $this->ok($this->reminders->day($request->user(), $date), 'OK');
    }

    /**
     * GET /api/v1/reminders/month?month=YYYY-MM
     *
     * A month of squares for the calendar: counts and a state per day, not the
     * occurrences themselves. Tapping a square calls the day endpoint for the
     * detail — a month of a busy user is several hundred items and the grid
     * draws a dot.
     */
    public function reminderMonth(Request $request): JsonResponse
    {
        $month = (string) $request->query('month', now()->format('Y-m'));

        abort_unless(
            (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month),
            422,
            'Send the month as YYYY-MM.',
        );

        return $this->ok($this->reminders->month($request->user(), $month), 'OK');
    }

    /**
     * GET /api/v1/reminders/score?days=30
     *
     * Adherence and the streak. Skipped occurrences are in neither half of
     * the fraction — deciding not to go to the gym on a rest day is not a
     * failure, and counting it as one teaches people to ignore the reminder
     * rather than answer it honestly.
     */
    public function reminderScore(Request $request): JsonResponse
    {
        $month = (string) $request->input('month', '');

        abort_if(
            $month !== '' && preg_match('/^\d{4}-\d{2}$/', $month) !== 1,
            422,
            'A month looks like 2026-09.',
        );

        return $this->ok(
            $this->reminders->score(
                $request->user(),
                (int) $request->integer('days', 30),
                $month === '' ? null : $month,
            ),
            'OK',
        );
    }

    /**
     * POST /api/v1/reminders
     */
    public function createReminder(SaveReminderRequest $request): JsonResponse
    {
        return $this->ok(
            $this->reminders->save($request->user(), $request->reminderData()),
            'Reminder saved.',
        );
    }

    /**
     * PUT /api/v1/reminders/{uuid}
     *
     * Either end may edit — the person it rings for as much as the person who
     * set it. An alarm you cannot silence on your own phone is not a
     * reminder, it is somebody else's control panel.
     */
    public function updateReminder(SaveReminderRequest $request, string $uuid): JsonResponse
    {
        return $this->ok(
            $this->reminders->save($request->user(), $request->reminderData(), $uuid),
            'Reminder updated.',
        );
    }

    /**
     * DELETE /api/v1/reminders/{uuid}
     *
     * Archives rather than deletes. The occurrences hanging off a reminder are
     * somebody's medicine history, and cascading them away because a reminder
     * was tidied up is a data loss nobody asks for and nobody can undo.
     */
    public function deleteReminder(Request $request, string $uuid): JsonResponse
    {
        $this->reminders->destroy($request->user(), $uuid);

        return $this->ok(['id' => $uuid], 'Reminder removed.');
    }

    /**
     * POST /api/v1/reminders/{uuid}/respond
     *
     * Accept or decline a reminder somebody set for you. Until it is
     * accepted it does not ring: another person putting an alarm on your
     * phone is a request, not a fact.
     */
    public function respondToReminder(RespondRequest $request, string $uuid): JsonResponse
    {
        $result = $this->reminders->respondToAssignment(
            $request->user(),
            $uuid,
            $request->wasAccepted(),
        );

        return $this->ok(
            [
                'changed' => $result['changed'],
                'reminder' => $result['reminder'],
            ],
            $result['message'],
        );
    }

    /**
     * POST /api/v1/reminders/{uuid}/occurrences
     *
     * Mark one occurrence done, snoozed or skipped.
     *
     * The occurrence is identified by its due instant rather than by an id,
     * because it usually does not exist yet — the future is computed, and the
     * row is written by this call at the moment somebody has an opinion about
     * it.
     */
    public function settleReminder(Request $request, string $uuid): JsonResponse
    {
        $dueAt = (string) $request->input('due_at', '');
        $status = (string) $request->input('status', '');

        // The wall clock the alarm rang at, "2026-09-17 08:03". Optional, so
        // an older build still settles — see ReminderService::settle for why
        // the instant on its own does not reliably name an occurrence.
        $dueLocal = (string) $request->input('due_local', '');

        // When the button was actually pressed. Matters because the score is
        // weighted by promptness — an answer that waited in the outbox for
        // three hours was still an answer given on time.
        $answeredAt = (string) $request->input('answered_at', '');

        abort_if($dueAt === '', 422, 'Say which occurrence.');

        return $this->ok(
            $this->reminders->settle(
                $request->user(),
                $uuid,
                $dueAt,
                $status,
                $dueLocal === '' ? null : $dueLocal,
                $answeredAt === '' ? null : $answeredAt,
            ),
            match ($status) {
                'done' => 'Marked done.',
                'snoozed' => 'Snoozed.',
                'skipped' => 'Skipped.',
                default => 'OK',
            },
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SOS
    |--------------------------------------------------------------------------
    |
    | One rule runs through all of these: the emergency numbers must appear
    | even when everything else has failed. They come from EmergencyDirectory,
    | which is a plain PHP array and touches no network, no cache and no
    | Google. Nearby places are the enhancement; the numbers are the feature.
    |
    */

    /**
     * GET /api/v1/sos
     *
     * The screen, cold: every service with its numbers and guidance, plus
     * whatever alert is already running.
     */
    public function sosOverview(Request $request): JsonResponse
    {
        return $this->ok($this->sos->overview($request->user()), 'OK');
    }

    /**
     * POST /api/v1/sos   { category?, latitude?, longitude?, note? }
     *
     * Raise the alarm. Records it, opens family location sharing, and tells
     * every family member with the app open.
     *
     * Idempotent: pressing twice returns the alert already running rather
     * than starting a second one. People double-tap buttons they press in a
     * panic, and one emergency must not become two alarms.
     */
    public function startSos(StartSosRequest $request): JsonResponse
    {
        return $this->ok(
            $this->sos->start($request->user(), $request->validated()),
            'Help is being alerted.',
        );
    }

    /**
     * POST /api/v1/sos/{uuid}   { category?, note?, latitude?, longitude? }
     *
     * Attach a category once the person has worked out who they need, or
     * refresh the position once a better fix arrives.
     */
    public function updateSos(StartSosRequest $request, string $uuid): JsonResponse
    {
        return $this->ok(
            $this->sos->update($request->user(), $uuid, $request->validated()),
            'OK',
        );
    }

    /**
     * POST /api/v1/sos/{uuid}/end   { status: resolved|cancelled|false_alarm }
     */
    public function endSos(Request $request, string $uuid): JsonResponse
    {
        return $this->ok($this->sos->end(
            $request->user(),
            $uuid,
            (string) $request->input('status', 'resolved'),
        ), 'Alert ended.');
    }

    /**
     * GET /api/v1/sos/history
     */
    public function sosHistory(Request $request): JsonResponse
    {
        return $this->ok(
            $this->sos->history($request->user(), (int) $request->integer('page', 1)),
            'OK',
        );
    }

    /**
     * GET /api/v1/sos/nearby?category=police&latitude=&longitude=
     * GET /api/v1/sos/nearby?query=apollo&latitude=&longitude=
     *
     * Nearest places of a kind, or a typed search. Proxied and cached here
     * rather than called from the app: the key is IP-restricted to this
     * server, and one paid lookup serves everybody in the same neighbourhood
     * for a week.
     *
     * Never carries phone numbers — those bill at a scarcer tier and are
     * fetched one at a time, on tap, through the endpoint below.
     */
    public function sosNearby(NearbyPlacesRequest $request): JsonResponse
    {
        $lat = (float) $request->validated('latitude');
        $lng = (float) $request->validated('longitude');

        $query = $request->validated('query');

        if ($query !== null && $query !== '') {
            $places = $this->places->search($query, $lat, $lng);

            return $this->ok([
                'places' => $places,
                'source' => 'search',
                'available' => $this->places->configured(),
                'reason' => $places === [] ? $this->places->failure() : null,
            ], 'OK');
        }

        $category = (string) $request->validated('category');
        $types = $this->directory->searchTypes($category);

        if ($types === []) {
            // A category with nothing to search for is not an error — several
            // of them are phone-only by design, and the client should show
            // the numbers without an empty list underneath.
            return $this->ok([
                'places' => [],
                'source' => 'none',
                'available' => true,
                'reason' => null,
            ], 'OK');
        }

        $places = $this->places->nearby(
            $types,
            $lat,
            $lng,
            (int) ($request->validated('radius') ?? PlacesService::DEFAULT_RADIUS),
        );

        return $this->ok([
            'places' => $places,
            'source' => 'nearby',
            'available' => $this->places->configured(),
            'reason' => $places === [] ? $this->places->failure() : null,
        ], 'OK');
    }

    /**
     * GET /api/v1/sos/places/{placeId}
     *
     * One place's phone number, for the Call button.
     *
     * The single most expensive call in the API — Google bills contact
     * details at its Enterprise tier — so it exists on its own, is throttled
     * hardest, and must only ever fire when somebody has actually tapped Call
     * on one specific place. Fetching numbers for a list of twenty would cost
     * twenty of these to answer a question nobody asked.
     */
    public function sosPlaceContact(string $placeId): JsonResponse
    {
        $contact = $this->places->contact($placeId);

        if ($contact === null) {
            return $this->fail('We could not get details for that place.', null, 404);
        }

        return $this->ok($contact, 'OK');
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
     * GET /api/v1/conversations/group-candidates?scope=connections|family
     *
     * Who this person may put in a group: everyone they follow or who
     * follows them plus their family, or family alone.
     */
    public function groupCandidates(Request $request): JsonResponse
    {
        $scope = $request->string('scope', GroupService::SCOPE_CONNECTIONS)->toString();

        abort_unless(
            in_array($scope, [GroupService::SCOPE_CONNECTIONS, GroupService::SCOPE_FAMILY], true),
            422,
            'Unknown scope.',
        );

        return $this->ok($this->groups->candidates($request->user(), $scope), 'OK');
    }

    /**
     * POST /api/v1/conversations/group   (multipart)
     *
     * {title, member_ids[], scope, avatar?}
     *
     * Multipart because the picture is chosen in the same step as the name —
     * a two-request create would leave a group with no photo whenever the
     * second one failed.
     */
    public function createGroup(CreateGroupRequest $request): JsonResponse
    {
        $me = $request->user();

        $conversation = $this->groups->create(
            $me,
            $request->title(),
            $request->memberIds(),
            $request->scope(),
            $request->file('avatar'),
        );

        return $this->created(
            $this->chat->presentConversation($me, $conversation, withMembers: true),
            'Group created.',
        );
    }

    /**
     * POST /api/v1/conversations/{uuid}/group   (multipart)
     *
     * {title?, avatar?}
     *
     * Any member, not only an admin: a group's name and face are how the
     * room describes itself, and being the person who created it is not a
     * rank.
     */
    public function updateGroup(UpdateGroupRequest $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        $conversation = $this->groups->update(
            $me,
            $this->chat->findConversation($me, $uuid),
            $request->title(),
            $request->file('avatar'),
        );

        return $this->ok(
            $this->chat->presentConversation($me, $conversation, withMembers: true),
            'Group updated.',
        );
    }

    /**
     * DELETE /api/v1/conversations/{uuid}/members/{member}
     *
     * Admins only. Removing somebody is done to them rather than to the
     * room, which is where the line between this and renaming sits.
     */
    public function removeGroupMember(
        Request $request,
        string $uuid,
        string $member,
    ): JsonResponse {
        $me = $request->user();

        $this->groups->removeMember(
            $me,
            $this->chat->findConversation($me, $uuid),
            $this->findUser($member),
        );

        return $this->ok(null, 'Removed from the group.');
    }

    /**
     * GET /api/v1/media/group/{uuid}   (signed)
     *
     * Streams a group picture. Not behind auth:sanctum — the signature is the
     * credential, which is what lets the URL go straight into an <img> tag.
     */
    public function streamGroupAvatar(Request $request, string $uuid): StreamedResponse
    {
        $conversation = Conversation::where('uuid', $uuid)->first();

        abort_if($conversation === null || blank($conversation->avatar_path), 404);

        $disk = Storage::disk(config('filesystems.default'));

        abort_unless($disk->exists($conversation->avatar_path), 404);

        return $disk->response($conversation->avatar_path);
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
            // Members only on the single-thread view. An inbox page of twenty
            // groups has no business carrying every member of each of them.
            $this->chat->presentConversation(
                $me,
                $this->chat->findConversation($me, $uuid),
                withMembers: true,
            ),
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

        $conversation = $this->chat->findConversation($me, $uuid);

        /*
         | Two different acts behind one endpoint.
         |
         | Leaving a direct thread keeps the row so a later message reopens
         | it. Leaving a group is a fact the room can see, and nothing pulls
         | you back in on its own.
         */
        if ($conversation->isGroup()) {
            $this->groups->leave($me, $conversation);

            return $this->ok(null, 'You left the group.');
        }

        $this->chat->leave($me, $conversation);

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

    /*
    |--------------------------------------------------------------------------
    | Live location
    |--------------------------------------------------------------------------
    */

    /**
     * GET /api/v1/location/live
     *
     * Everything the map screen needs to paint itself from cold: who is
     * currently sharing with me, where each of them is, what I am sharing,
     * and how often my own phone should be looking.
     *
     * The socket carries deltas after this and nothing else. A dropped
     * socket is therefore a stale map rather than an empty one — the same
     * contract as chat, for the same reason.
     */
    public function liveLocations(Request $request): JsonResponse
    {
        return $this->ok($this->locations->live($request->user()), 'OK');
    }

    /**
     * POST /api/v1/location/ping   { fixes: [ {latitude, longitude, ...} ] }
     *
     * A buffer of readings from one phone. Accepts a bare fix too.
     *
     * Always answers with what to do next, including "stop" — a client that
     * never hears stop from the server is a client that tracks forever.
     */
    public function pingLocation(PingLocationRequest $request): JsonResponse
    {
        return $this->ok(
            $this->locations->ping($request->user(), $request->validated('fixes')),
            'OK',
        );
    }

    /**
     * POST /api/v1/location/share   { audience, conversation_id?, minutes? }
     *
     * Begin sharing. A conversation share announces itself with a message in
     * the thread, in the same call — there is no path that starts one
     * silently.
     */
    public function shareLocation(ShareLocationRequest $request): JsonResponse
    {
        return $this->ok(
            $this->locations->share($request->user(), $request->validated()),
            'Sharing your location.',
        );
    }

    /**
     * POST /api/v1/location/stop   { share_id? }
     *
     * Without a share_id this stops everything, which is what the status-bar
     * button does: the action somebody is most likely to take in a hurry
     * should not first ask them which share they meant.
     */
    public function stopLocation(Request $request): JsonResponse
    {
        return $this->ok(
            $this->locations->stop($request->user(), $request->input('share_id')),
            'Location sharing stopped.',
        );
    }

    /**
     * POST /api/v1/location/pin   { conversation_id, latitude, longitude }
     *
     * A static "here is where I am". No share, nothing to expire.
     */
    public function pinLocation(PinLocationRequest $request): JsonResponse
    {
        return $this->created($this->locations->pin(
            $request->user(),
            $request->validated('conversation_id'),
            (float) $request->validated('latitude'),
            (float) $request->validated('longitude'),
        ), 'Location sent.');
    }

    /**
     * GET /api/v1/location/{uuid}/trail?since=
     *
     * The recent path behind somebody's marker. Capped at 24 hours and
     * thinned on the way out.
     */
    public function locationTrail(Request $request, string $uuid): JsonResponse
    {
        return $this->ok($this->locations->trail(
            $request->user(),
            $uuid,
            $request->query('since'),
        ), 'OK');
    }

    /**
     * GET /api/v1/location/{uuid}/history?date=2026-09-11&offset=330
     *
     * One person's day as a timeline of stays and journeys.
     *
     * `offset` is minutes east of UTC, sent by the phone rather than read
     * from a stored profile. The question is "what did Tuesday look like
     * where I am now", and somebody reading this in a different timezone from
     * the one they signed up in is asking about the day they are living in.
     */
    public function locationHistory(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'offset' => ['nullable', 'integer', 'between:-840,840'],
        ]);

        return $this->ok($this->history->day(
            $request->user(),
            $uuid,
            $validated['date'],
            (int) ($validated['offset'] ?? 0),
        ), 'OK');
    }

    /**
     * GET /api/v1/location/{uuid}/history/days?month=2026-09&offset=330
     *
     * Which days in a month have anything recorded, so the calendar can mark
     * them. One grouped query rather than thirty day requests.
     */
    public function locationHistoryDays(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'offset' => ['nullable', 'integer', 'between:-840,840'],
        ]);

        return $this->ok($this->history->days(
            $request->user(),
            $uuid,
            $validated['month'],
            (int) ($validated['offset'] ?? 0),
        ), 'OK');
    }

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    |
    | Somewhere to go, a line to get there, and a note to the family that you
    | are on your way.
    |
    | Deliberately not a fourth endpoint: there is no turn-by-turn here. The
    | field mask in RoutingService is what keeps every request on the Basic
    | tier, and step-by-step manoeuvres would move the whole feature onto
    | Advanced billing for a screen nobody asked for.
    */

    /**
     * GET /api/v1/location/search?q=vashi+station&latitude=&longitude=
     *
     * The same Places proxy the SOS screen uses, biased towards wherever the
     * caller is standing rather than restricted to it - somebody typing
     * "station" wants the near one first but should still find the one they
     * actually meant.
     *
     * Called on each keystroke behind a client-side debounce, so the throttle
     * is loose and the cache underneath it does the real work: one paid
     * lookup answers the same query for everybody nearby for a week.
     */
    public function searchDestinations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $lat = isset($validated['latitude']) ? (float) $validated['latitude'] : null;
        $lng = isset($validated['longitude']) ? (float) $validated['longitude'] : null;

        $places = $this->places->search($validated['q'], $lat, $lng);

        return $this->ok([
            'places' => $places,
            'available' => $this->places->configured(),

            // Why the list is empty, when it is empty. "No results" and "the
            // key is not enabled" look identical from the app otherwise, and
            // the second one is a thing somebody has to go and fix.
            'reason' => $places === [] ? $this->places->failure() : null,
        ], 'OK');
    }

    /**
     * POST /api/v1/location/route
     *   { from_latitude, from_longitude, to_latitude, to_longitude, mode? }
     *
     * A distance, a duration, and an encoded polyline to draw. Nothing else.
     *
     * The origin comes from the request rather than from the caller's last
     * stored fix, because the phone's current reading is seconds old and the
     * stored one can be minutes old - and a route that starts a street behind
     * you is worse than no route.
     *
     * A failure here is a 422 carrying the reason in plain words, not a 500:
     * every way this can fail is something a person can act on.
     */
    public function routeTo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_latitude' => ['required', 'numeric', 'between:-90,90'],
            'from_longitude' => ['required', 'numeric', 'between:-180,180'],
            'to_latitude' => ['required', 'numeric', 'between:-90,90'],
            'to_longitude' => ['required', 'numeric', 'between:-180,180'],
            'mode' => ['nullable', 'string', 'in:'.implode(',', RoutingService::MODES)],
        ]);

        $route = $this->routing->route(
            (float) $validated['from_latitude'],
            (float) $validated['from_longitude'],
            (float) $validated['to_latitude'],
            (float) $validated['to_longitude'],
            (string) ($validated['mode'] ?? 'DRIVE'),
        );

        if ($route === null) {
            return $this->fail(
                $this->routing->failure() ?? 'We could not work out a route.',
                null,
                422,
            );
        }

        return $this->ok($route, 'OK');
    }

    /**
     * POST /api/v1/location/trip/start
     *   { label, latitude, longitude, duration_seconds }
     *
     * Announce a journey. This is what turns a family marker from "Aisha" into
     * "Aisha - on the way to Dadar, 12 min".
     *
     * Starting a second one replaces the first rather than erroring: a person
     * who changes their mind about where they are going has not made a
     * mistake, and refusing them would leave a stale destination on the map.
     *
     * Nothing is broadcast from here. The trip rides along on the next
     * position ping, which is never more than thirty seconds away and is
     * already going to every viewer - a second socket event for the same
     * change would only race the first.
     */
    public function startTrip(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],

            // A day's ceiling. Anything longer is a bad ETA rather than a long
            // journey, and an ETA three days out would sit on the map forever.
            'duration_seconds' => ['required', 'integer', 'between:0,86400'],
        ]);

        return $this->ok($this->locations->startTrip(
            $request->user(),
            (string) $validated['label'],
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            (int) $validated['duration_seconds'],
        ), 'On your way.');
    }

    /**
     * POST /api/v1/location/trip/end
     *
     * Arrived, or gave up. Idempotent - ending a trip that is not running is
     * a no-op, which matters because this is what the app calls on the way
     * out of the navigation screen whether or not one was ever started.
     */
    public function endTrip(Request $request): JsonResponse
    {
        $this->locations->endTrip($request->user());

        return $this->ok(['trip' => null], 'Trip ended.');
    }

    /*
    |--------------------------------------------------------------------------
    | Family places
    |--------------------------------------------------------------------------
    |
    | The named circles a person sets up — Home, School, the grandparents' —
    | which turn "28.6139, 77.2090" into "At School" and fire an arrival when
    | somebody crosses into one.
    |
    | Every route here is scoped to the caller's own places. A place belongs
    | to whoever made it, and the labels somebody sees are computed against
    | theirs; see the family_places migration for why that is the only
    | coherent reading of a family graph with no family *group* in it.
    */

    /**
     * GET /api/v1/location/places
     *
     * My own places. Also returned inside `location/live`, so the map does
     * not need this call to draw — this one is for the manage screen.
     */
    public function places(Request $request): JsonResponse
    {
        return $this->ok([
            'places' => $this->familyPlaces->forOwner($request->user())
                ->map(fn ($place) => $this->familyPlaces->present($place))
                ->values()
                ->all(),
        ], 'OK');
    }

    /**
     * POST /api/v1/location/places
     *
     * Create one.
     */
    public function createPlace(SavePlaceRequest $request): JsonResponse
    {
        $place = $this->familyPlaces->save(
            $request->user(),
            null,
            $request->validated(),
        );

        return $this->created(
            ['place' => $this->familyPlaces->present($place)],
            $place->name.' added.',
        );
    }

    /**
     * PATCH /api/v1/location/places/{uuid}
     *
     * Rename, move, or resize one.
     *
     * Moving or resizing closes every open visit inside it — see
     * FamilyPlaceService::save. Without that, everybody who was standing in
     * the old circle stays marked as inside it forever.
     */
    public function updatePlace(SavePlaceRequest $request, string $uuid): JsonResponse
    {
        $place = $this->familyPlaces->save(
            $request->user(),
            $uuid,
            $request->validated(),
        );

        return $this->ok(
            ['place' => $this->familyPlaces->present($place)],
            $place->name.' updated.',
        );
    }

    /**
     * DELETE /api/v1/location/places/{uuid}
     */
    public function deletePlace(Request $request, string $uuid): JsonResponse
    {
        $this->familyPlaces->delete($request->user(), $uuid);

        return $this->ok([], 'Place removed.');
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
