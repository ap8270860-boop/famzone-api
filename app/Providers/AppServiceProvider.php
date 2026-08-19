<?php

namespace App\Providers;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Unauthenticated visitors to /admin/* get the admin login screen,
        // not the front-end login route (which does not exist yet).
        Authenticate::redirectUsing(function (Request $request) {
            return $request->is('admin', 'admin/*') ? route('admin.login') : '/';
        });

        // Admins who are already signed in should never see the login form.
        RedirectIfAuthenticated::redirectUsing(function (Request $request) {
            return $request->is('admin', 'admin/*') ? route('admin.dashboard') : '/';
        });
    }
}
