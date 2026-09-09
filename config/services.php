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
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'timeout' => env('GEMINI_TIMEOUT', 30),
        'max_output_tokens' => env('GEMINI_MAX_OUTPUT_TOKENS', 800),
        // 0 = matikan mode berpikir. Kosongkan kalau model yang dipakai
        // tidak mendukung thinkingConfig.
        'thinking_budget' => env('GEMINI_THINKING_BUDGET', 0),
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
        'timeout' => env('KIRIMDEV_TIMEOUT', 15),

        // Balas otomatis pesan masuk (echo, belum AI). Sengaja default false:
        // nomor yang dipakai adalah nomor bisnis asli, jangan sampai semua
        // pelanggan dibalas bot saat testing. Hanya KIRIMDEV_TEST_NUMBER
        // yang akan dibalas.
        'auto_reply' => env('KIRIMDEV_AUTO_REPLY', false),

        // Balas pakai Gemini (tanya jawab produk Benah). Kalau false, balasan
        // tetap echo seperti sebelumnya. Butuh auto_reply true juga.
        'ai_reply' => env('KIRIMDEV_AI_REPLY', false),

        // Proses balasan lewat queue (butuh `php artisan queue:work` jalan).
        // Disarankan true begitu AI aktif, karena Kirimdev timeout 10 detik.
        'queue' => env('KIRIMDEV_QUEUE', false),

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
