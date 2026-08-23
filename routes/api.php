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
