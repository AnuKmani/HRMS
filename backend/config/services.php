<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging
    |--------------------------------------------------------------------------
    |
    | The service-account JSON lives **outside the repository**, always.
    | Two ways in, because hosts differ:
    |
    |   FIREBASE_CREDENTIALS          path to the JSON file on disk
    |   FIREBASE_CREDENTIALS_BASE64   the JSON itself, base64-encoded
    |
    | The second exists for platforms where writing a secret file is more
    | awkward than setting one variable. If neither is set - every
    | developer machine, every CI run - the application binds
    | DisabledFcmGateway and says so in the log instead of pretending a
    | push was delivered. See App\Providers\NotificationServiceProvider.
    |
    | Neither key carries a value in .env.example; `project_id` is optional
    | and only overrides what is already inside the JSON.
    |
    */

    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'credentials_base64' => env('FIREBASE_CREDENTIALS_BASE64'),
        'project_id' => env('FIREBASE_PROJECT_ID'),
    ],

];
