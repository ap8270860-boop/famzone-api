<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
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
| Breaking changes get a v2 group alongside v1, never an edit to v1.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    Route::get('ping', [V1Controller::class, 'ping'])->name('ping');

    /*
     | Authentication — public.
     |
     | Throttled harder than the default: these endpoints send SMS and guess
     | credentials, so they are the ones worth abusing. The OtpService applies
     | its own per-number limits on top of this per-IP one.
     */
    Route::prefix('auth')->name('auth.')->middleware('throttle:10,1')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('otp/send', [AuthController::class, 'sendOtp'])->name('otp.send');
        Route::post('otp/verify', [AuthController::class, 'verifyOtp'])->name('otp.verify');
    });

    /*
     | Authenticated.
     */
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('me', function (Illuminate\Http\Request $request) {
            return response()->json([
                'success' => true,
                'message' => 'OK',
                'data' => new App\Http\Resources\Api\V1\UserResource($request->user()),
            ]);
        })->name('me');
    });
});
