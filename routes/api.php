<?php

use App\Http\Controllers\Api\V1\V1Controller;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Loaded with the "api" middleware group and already prefixed with "api" by
| bootstrap/app.php, so the "v1" group below produces /api/v1/{route} and
| route names of the form api.v1.{name}.
|
| Every endpoint is handled by V1Controller. Breaking changes get a v2 group
| and a V2Controller alongside it, never an edit to v1.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    Route::get('ping', [V1Controller::class, 'ping'])->name('ping');

    /*
     | Signed media.
     |
     | Not behind auth:sanctum on purpose — the signature in the query string
     | is the credential. That lets the link go straight into an <img> tag,
     | which cannot send an Authorization header.
     */
    Route::get('media/avatar/{uuid}/{slot}', [V1Controller::class, 'streamAvatar'])
        ->middleware('signed')
        ->where('slot', 'primary|alternate')
        ->name('media.avatar');


    /*
     | Signed post images. Same contract as the avatar route above.
     */
    Route::get('media/post/{uuid}', [V1Controller::class, 'streamPostImage'])
        ->middleware('signed')
        ->name('media.post');

    /*
     | Public authentication.
     |
     | Throttled harder than the default: these endpoints send SMS and guess
     | credentials, so they are the ones worth abusing. OtpService applies its
     | own per-number limits on top of this per-IP one.
     */
    Route::prefix('auth')->name('auth.')->middleware('throttle:10,1')->group(function () {
        Route::post('register', [V1Controller::class, 'register'])->name('register');
        Route::post('login', [V1Controller::class, 'login'])->name('login');
        Route::post('otp/send', [V1Controller::class, 'sendOtp'])->name('otp.send');
        Route::post('otp/verify', [V1Controller::class, 'verifyOtp'])->name('otp.verify');
    });

    /*
     | Authenticated.
     */
    Route::middleware('auth:sanctum')->group(function () {

        Route::post('auth/logout', [V1Controller::class, 'logout'])->name('auth.logout');
        Route::get('me', [V1Controller::class, 'me'])->name('me');


        /*
         | People, following and family.
         |
         | Every relationship endpoint answers with the full profile of the
         | other person, including both directions of the relationship, so one
         | response is enough for the client to repaint whatever screen the
         | action was taken from.
         */
        Route::get('users/search', [V1Controller::class, 'searchUsers'])
            ->middleware('throttle:60,1')
            ->name('users.search');

        Route::prefix('users/{uuid}')->name('users.')->group(function () {
            Route::get('/', [V1Controller::class, 'showUser'])->name('show');
            Route::get('followers', [V1Controller::class, 'userFollowers'])->name('followers');
            Route::get('following', [V1Controller::class, 'userFollowing'])->name('following');
            Route::get('posts', [V1Controller::class, 'userPosts'])->name('posts');
            Route::get('tagged-posts', [V1Controller::class, 'userTaggedPosts'])
                ->name('posts.tagged');

            Route::post('follow', [V1Controller::class, 'followUser'])
                ->middleware('throttle:60,1')
                ->name('follow');
            Route::delete('follow', [V1Controller::class, 'unfollowUser'])->name('unfollow');
            Route::delete('follower', [V1Controller::class, 'removeFollower'])->name('follower.remove');

            Route::post('block', [V1Controller::class, 'blockUser'])
                ->middleware('throttle:30,1')
                ->name('block');
            Route::delete('block', [V1Controller::class, 'unblockUser'])
                ->name('unblock');

            Route::post('family', [V1Controller::class, 'inviteToFamily'])
                ->middleware('throttle:30,1')
                ->name('family.invite');
        });


        /*
         | Posts. Creating one uploads an image, so it is capped low; reading
         | is as cheap as any other list.
         */
        Route::post('posts', [V1Controller::class, 'createPost'])
            ->middleware('throttle:20,1')
            ->name('posts.store');

        Route::get('posts/{uuid}', [V1Controller::class, 'showPost'])->name('posts.show');
        Route::delete('posts/{uuid}', [V1Controller::class, 'deletePost'])->name('posts.destroy');

        Route::post('posts/{uuid}/like', [V1Controller::class, 'likePost'])
            ->middleware('throttle:120,1')
            ->name('posts.like');
        Route::delete('posts/{uuid}/like', [V1Controller::class, 'unlikePost'])
            ->middleware('throttle:120,1')
            ->name('posts.unlike');

        Route::get('posts/{uuid}/likes', [V1Controller::class, 'postLikes'])
            ->name('posts.likes');

        Route::get('follow-requests', [V1Controller::class, 'followRequests'])
            ->name('follow-requests.index');
        Route::post('follow-requests/{uuid}/respond', [V1Controller::class, 'respondToFollowRequest'])
            ->name('follow-requests.respond');

        Route::get('blocks', [V1Controller::class, 'blockedAccounts'])
            ->name('blocks.index');

        Route::get('family', [V1Controller::class, 'family'])->name('family.index');
        Route::delete('family/{uuid}', [V1Controller::class, 'removeFamilyMember'])
            ->name('family.remove');
        Route::post('family-invites/{uuid}/respond', [V1Controller::class, 'respondToFamilyInvite'])
            ->name('family-invites.respond');

        /*
         | Notifications. unread-count is polled on every screen open, so it is
         | deliberately the cheapest endpoint here.
         */
        Route::prefix('notifications')->name('notifications.')->group(function () {
            Route::get('/', [V1Controller::class, 'notifications'])->name('index');
            Route::get('unread-count', [V1Controller::class, 'unreadNotificationCount'])
                ->middleware('throttle:120,1')
                ->name('unread');
            Route::post('read-all', [V1Controller::class, 'readAllNotifications'])->name('read-all');
            Route::post('{uuid}/read', [V1Controller::class, 'readNotification'])->name('read');
        });

        /*
         | Safety. The status endpoint is polled on every home-screen open, so
         | it gets the standard limit; check-in is a deliberate human action
         | and is capped far lower.
         */
        Route::prefix('safety')->name('safety.')->group(function () {
            Route::get('status', [V1Controller::class, 'safetyStatus'])->name('status');

            Route::post('check-in', [V1Controller::class, 'checkIn'])
                ->middleware('throttle:20,1')
                ->name('check-in');

            Route::get('check-ins', [V1Controller::class, 'checkInHistory'])
                ->name('check-ins');
        });

        /*
         | Chat.
         |
         | Messages travel over HTTP; the websocket only announces that one
         | arrived. Every screen can rebuild itself from these endpoints
         | alone, which is what makes a dropped socket a delay rather than a
         | lost message.
         */
        Route::post('conversations', [V1Controller::class, 'startConversation'])
            ->middleware('throttle:60,1')
            ->name('conversations.store');

        Route::get('conversations', [V1Controller::class, 'conversations'])
            ->name('conversations.index');

        /*
         | Before conversations/{uuid}, so the literal segment is not
         | swallowed by the parameter — the same ordering rule that puts
         | users/search above users/{uuid}.
         */
        Route::get('conversations/unread-count', [V1Controller::class, 'chatUnreadCount'])
            ->middleware('throttle:120,1')
            ->name('conversations.unread');

        Route::prefix('conversations/{uuid}')->name('conversations.')->group(function () {
            Route::get('/', [V1Controller::class, 'showConversation'])->name('show');
            Route::delete('/', [V1Controller::class, 'leaveConversation'])->name('leave');

            Route::get('messages', [V1Controller::class, 'conversationMessages'])
                ->name('messages');

            Route::post('messages', [V1Controller::class, 'sendMessage'])
                ->middleware('throttle:60,1')
                ->name('messages.store');

            /*
             | Receipts are fired on every scroll and every arriving message,
             | so they are throttled far looser than a mutation of their size
             | would normally be. Both are idempotent no-ops when the
             | watermark is already ahead.
             */
            Route::post('read', [V1Controller::class, 'markConversationRead'])
                ->middleware('throttle:240,1')
                ->name('read');

            Route::post('delivered', [V1Controller::class, 'markConversationDelivered'])
                ->middleware('throttle:240,1')
                ->name('delivered');

            Route::post('accept', [V1Controller::class, 'acceptConversation'])->name('accept');
        });

        Route::delete('messages/{uuid}', [V1Controller::class, 'deleteMessage'])
            ->name('messages.destroy');

        /*
         | Presence heartbeat. Runs every 45 seconds for every foregrounded
         | app, and writes one indexed column.
         */
        Route::post('presence/ping', [V1Controller::class, 'presencePing'])
            ->middleware('throttle:120,1')
            ->name('presence.ping');


        /*
         | Profile. The username check runs on every keystroke (debounced), so
         | it gets a looser throttle than the mutations beside it.
         */
        Route::prefix('profile')->name('profile.')->group(function () {
            Route::get('/', [V1Controller::class, 'me'])->name('show');
            Route::patch('/', [V1Controller::class, 'updateProfile'])->name('update');

            Route::get('username/check', [V1Controller::class, 'checkUsername'])
                ->middleware('throttle:60,1')
                ->name('username.check');

            Route::post('password', [V1Controller::class, 'updatePassword'])
                ->middleware('throttle:6,1')
                ->name('password');

            Route::post('avatar', [V1Controller::class, 'uploadAvatar'])
                ->middleware('throttle:20,1')
                ->name('avatar.upload');
            Route::delete('avatar', [V1Controller::class, 'deleteAvatar'])
                ->name('avatar.delete');
        });
    });
});
