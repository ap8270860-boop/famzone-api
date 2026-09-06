<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google
    |--------------------------------------------------------------------------
    |
    | The Places key, and only the Places key. This is a different credential
    | from the Maps SDK keys in the mobile app: those are restricted by
    | package name and signing fingerprint and are expected to ship inside
    | the binary, whereas this one is restricted by IP to this server and must
    | never leave it.
    |
    | Empty is a supported state. PlacesService checks before every call and
    | degrades to "no nearby places found" — the emergency numbers come from
    | EmergencyDirectory and never touch Google, so an unconfigured or
    | exhausted key can never stop somebody dialling 112.
    |
    */
    'google' => [
        'places_key' => env('GOOGLE_PLACES_KEY'),
    ],

];
