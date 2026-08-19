<?php

use App\Http\Controllers\Api\V1\V1Controller;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| These routes are loaded with the "api" middleware group and are already
| prefixed with "api" by bootstrap/app.php, so the "v1" prefix below
| produces URLs of the form:
|
|     /api/v1/{route}
|
| Route names follow the same shape: api.v1.{name}
|
| Every client — Flutter (iOS + Android) and the React dashboard — talks to
| this same versioned surface. When a breaking change is needed, add a v2
| group alongside v1 rather than editing v1 in place.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    /*
     * Public routes — no authentication required.
     */
    Route::get('ping', [V1Controller::class, 'ping'])->name('ping');

    /*
     * Protected routes.
     *
     * Sanctum is not installed yet (Phase 0, step 3). Once it is, move the
     * authenticated endpoints inside:
     *
     *     Route::middleware('auth:sanctum')->group(function () { ... });
     */
});
