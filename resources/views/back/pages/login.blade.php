@extends('back.layout.auth-layout')

@section('pageTitle', isset($pageTitle) ? $pageTitle : 'Page Title here')

@section('content')

<div class="w-100">
    <div class="d-flex flex-column h-100">
        <div class="mb-4 mb-md-5 text-center">
            <a href="index.html" class="d-block auth-logo">
                <img src="/back/assets/images/logo-sm.svg" alt="" height="28"> <span class="logo-txt">FAMZONE</span>
            </a>
        </div>
        <div class="auth-content my-auto">
            <div class="text-center">
                <h5 class="mb-0">Welcome Back !</h5>
                <p class="text-muted mt-2">Sign in to continue to SavvyDeals.</p>
            </div>
            <form class="mt-4 pt-2" action="{{ route('admin.login_handler') }}" method="POST">
                <x-form-alerts></x-form-alerts>
                @csrf
                <div class="mb-3">
                    <label class="form-label">Username / Email</label>
                    <input type="text" class="form-control" id="username" placeholder="Enter username / email" name="login_id" value="{{ old('login_id') }}">
                    @error('login_id')
                        <span class="text-danger">{{ $message }}</span>
                    @enderror
                </div>
                <div class="mb-3">
                    <div class="d-flex align-items-start">
                        <div class="flex-grow-1">
                            <label class="form-label">Password</label>
                        </div>
                    </div>

                    <div class="input-group auth-pass-inputgroup">
                        <input type="password" name="password" class="form-control" placeholder="Enter password" aria-label="Password" aria-describedby="password-addon">
                        <button class="btn btn-light shadow-none ms-0" type="button" id="password-addon"><i class="mdi mdi-eye-outline"></i></button>
                    </div>
                    @error('password')
                        <span class="text-danger">{{ $message }}</span>
                    @enderror
                </div>
                <div class="mb-3">
                    <button class="btn btn-primary w-100 waves-effect waves-light" type="submit">Log In</button>
                </div>
            </form>


        </div>
        <div class="mt-4 mt-md-5 text-center">
            <p class="mb-0">© <script>document.write(new Date().getFullYear())</script> SAVVY DEALS.</p>
        </div>
    </div>
</div>

@endsection
