<?php

namespace App\Providers;

use App\Services\Otp\Channels\LogOtpSender;
use App\Services\Otp\Channels\Msg91OtpSender;
use App\Services\Otp\Contracts\OtpSender;
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
        // Which channel delivers an OTP is a config choice, so swapping
        // providers never touches the service or the controllers.
        $this->app->bind(OtpSender::class, fn () => match (config('otp.driver')) {
            'msg91' => new Msg91OtpSender,
            default => new LogOtpSender,
        });
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
