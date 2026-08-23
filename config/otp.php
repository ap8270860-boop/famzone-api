<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Delivery channel
    |--------------------------------------------------------------------------
    |
    | "log"   — writes the code to the log and returns it in the API response.
    |           Local and staging only.
    | "msg91" — real SMS. Requires MSG91_AUTH_KEY.
    |
    */
    'driver' => env('OTP_DRIVER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Expose the code in API responses
    |--------------------------------------------------------------------------
    |
    | Lets the app show the code on screen while no SMS provider is wired up.
    | Hard-guarded: never true in production, whatever the env file says.
    |
    */
    // env() only — config files are loaded before the container binds
    // 'env', so app()->isProduction() blows up here. OtpService applies
    // a second, runtime check that production can never be exposed.
    'expose_in_response' => env('OTP_EXPOSE', true)
        && env('APP_ENV', 'production') !== 'production',

    'length' => env('OTP_LENGTH', 6),

    /** Minutes a code stays valid. */
    'ttl' => env('OTP_TTL', 10),

    /** Wrong guesses allowed before the code is burned. */
    'max_attempts' => env('OTP_MAX_ATTEMPTS', 5),

    /** Seconds a user must wait before asking for another code. */
    'resend_cooldown' => env('OTP_RESEND_COOLDOWN', 60),

    /** Codes a single number may request per hour. */
    'hourly_limit' => env('OTP_HOURLY_LIMIT', 5),

    'msg91' => [
        'auth_key' => env('MSG91_AUTH_KEY'),
        'template_id' => env('MSG91_TEMPLATE_ID'),
        'sender_id' => env('MSG91_SENDER_ID', 'SFMLY'),
    ],
];
