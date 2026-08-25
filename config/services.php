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
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Auto Export ingest
    |--------------------------------------------------------------------------
    |
    | Shared secret the phone sends as the `X-API-Key` header on every POST to
    | /api/ingest. Read through config (never env() at request time) so a
    | cached config keeps working.
    |
    */

    'ingest' => [
        'api_key' => env('INGEST_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic (the vision pipeline, step 5)
    |--------------------------------------------------------------------------
    |
    | The key for the meal-photo analysis call. Read through config rather than
    | env() at call time so a cached config keeps working, and kept HERE rather
    | than in config/health.php because health.php is about how this app models
    | a day and this is a credential for somebody else's service.
    |
    | The value is never logged and never leaves the job: `vision_requests`
    | stores the response, the token counts and the latency, none of which
    | contain it.
    |
    */

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

];
