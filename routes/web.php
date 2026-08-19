<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

/*
|--------------------------------------------------------------------------
| Admin panel
|--------------------------------------------------------------------------
|
| Everything below is served under the "admin" prefix and authenticates
| against the "admin" guard (config/auth.php), which is backed by the
| App\Models\Admin model rather than App\Models\User.
|
*/

Route::prefix('admin')->name('admin.')->group(function () {

    // Signed-out admins only — an authenticated admin hitting these is
    // bounced to the dashboard by RedirectIfAuthenticated.
    Route::middleware('guest:admin')->group(function () {
        Route::get('login', [AuthController::class, 'loginForm'])->name('login');
        Route::post('login', [AuthController::class, 'loginHandler'])->name('login_handler');
    });

    // Signed-in admins only.
    Route::middleware('auth:admin')->group(function () {
        Route::get('dashboard', [AdminController::class, 'adminDashboard'])->name('dashboard');
        Route::post('logout', [AuthController::class, 'logoutHandler'])->name('logout');
    });
});
