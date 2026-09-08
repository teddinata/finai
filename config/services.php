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

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'kirimdev' => [
        'api_key' => env('KIRIMDEV_API_KEY'),
        'webhook_secret' => env('KIRIMDEV_WEBHOOK_SECRET'),
        'phone_number_id' => env('KIRIMDEV_PHONE_NUMBER_ID'),
        'base_url' => env('KIRIMDEV_BASE_URL', 'https://api.kirimdev.com'),

        // Selama smoke test biarkan false: payload tetap masuk walau
        // signature belum cocok, hasil verifikasinya dilaporkan di response.
        'enforce_signature' => env('KIRIMDEV_ENFORCE_SIGNATURE', false),
        'signature_tolerance' => env('KIRIMDEV_SIGNATURE_TOLERANCE', 300),

        // Token untuk membuka GET /api/webhooks/kirimdev-test/last di server.
        'debug_token' => env('KIRIMDEV_DEBUG_TOKEN'),
        'verify_token' => env('KIRIMDEV_VERIFY_TOKEN'),
        'log_channel' => env('KIRIMDEV_LOG_CHANNEL', 'kirimdev'),

        // Pilot: batasi AI hanya untuk satu nomor + user tertentu.
        'test_number' => env('KIRIMDEV_TEST_NUMBER'),
        'test_user_id' => env('KIRIMDEV_TEST_USER_ID'),
    ],

];
