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

    'ksef' => [
        'environment' => env('KSEF_ENVIRONMENT'),
        'api_version' => env('KSEF_API_VERSION'),
        'base_url' => env('KSEF_BASE_URL'),
        'gateway_url' => env('KSEF_GATEWAY_URL'),
        'status_url' => env('KSEF_STATUS_URL'),
        'public_key_id' => env('KSEF_PUBLIC_KEY_ID'),
        'public_key_sha256' => env('KSEF_PUBLIC_KEY_SHA256'),
        'access_token' => env('KSEF_TOKEN', env('KSEF_ACCESS_TOKEN')),
        'context_identifier_type' => env('KSEF_CONTEXT_IDENTIFIER_TYPE'),
        'context_identifier_value' => env('KSEF_CONTEXT_IDENTIFIER_VALUE'),
        'auth_status_attempts' => env('KSEF_AUTH_STATUS_ATTEMPTS', 6),
        'auth_status_delay_ms' => env('KSEF_AUTH_STATUS_DELAY_MS', 500),
    ],

];
