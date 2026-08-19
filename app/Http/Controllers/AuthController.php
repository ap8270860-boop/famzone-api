<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function loginForm(Request $request) {
        $data = [
            'pageTitle' => 'Admin login'
        ];
        return view('back.pages.login', $data);
    }

    public function loginHandler(Request $request)
    {
        $fieldType = filter_var($request->login_id, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        if ($fieldType == 'email') {
            $request->validate([
                'login_id' => 'required|email|exists:admins,email',
                'password' => 'required|min:5',
            ], [
                'login_id.required' => 'Enter your email or username',
                'login_id.email'    => 'Invalid email address',
                'login_id.exists'   => 'No account found for this email',
                'password.required' => 'Password is required',
                'password.min'      => 'Password must be at least 5 characters',
            ]);
        } else {
            $request->validate([
                'login_id' => 'required|exists:admins,username',
                'password' => 'required|min:5',
            ], [
                'login_id.required' => 'Enter your email or username',
                'login_id.exists'   => 'No account found for this username',
                'password.required' => 'Password is required',
                'password.min'      => 'Password must be at least 5 characters',
            ]);
        }

        $creds = [
            $fieldType => $request->login_id,
            'password' => $request->password,
        ];

        if (Auth::guard('admin')->attempt($creds, $request->boolean('remember'))) {
            $request->session()->regenerate();

            if (!Auth::guard('admin')->user()->is_active) {
                Auth::guard('admin')->logout();
                return redirect()->route('admin.login')
                    ->withInput()
                    ->with('fail', 'Your account has been deactivated.');
            }

            return redirect()->route('admin.dashboard');
        }

        return redirect()->route('admin.login')
            ->withInput()
            ->with('fail', 'Invalid credentials, please try again.');
    }

    public function logoutHandler(Request $request)
    {
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')
            ->with('success', 'You have been logged out.');
    }
}
