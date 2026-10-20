<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Xendit API Keys
    |--------------------------------------------------------------------------
    |
    | Your Xendit API keys. Get them from: https://dashboard.xendit.co/settings/developers
    |
    */

    'secret_key' => env('XENDIT_SECRET_KEY'),
    'public_key' => env('XENDIT_PUBLIC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Webhook token for verifying callbacks from Xendit.
    | Generate a random string and set it in your .env file.
    | Then configure the same token in Xendit dashboard.
    |
    */

    'webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),

    /*
    | Xendit menerbitkan callback token yang berbeda per endpoint webhook, jadi
    | satu token saja bikin channel lain ditolak 401 dan di-retry terus.
    | Isi XENDIT_WEBHOOK_TOKENS dengan semua token, dipisah koma.
    */
    'webhook_tokens' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string)env('XENDIT_WEBHOOK_TOKENS', ''))
    ), fn($token) => $token !== '')),

    'webhook_url' => env('APP_URL') . '/api/webhooks/xendit',

    /*
    |--------------------------------------------------------------------------
    | Payment Settings
    |--------------------------------------------------------------------------
    */

    'invoice_duration' => 86400, // 24 hours in seconds
    'currency' => 'IDR',

    /*
    |--------------------------------------------------------------------------
    | Metode Pembayaran Aktif
    |--------------------------------------------------------------------------
    |
    | Supaya channel yang sedang bermasalah bisa disembunyikan dari pengguna
    | lewat .env, tanpa perlu deploy ulang. Contoh mematikan QRIS:
    |
    |   XENDIT_ENABLED_METHODS=invoice,virtual_account,ewallet
    |
    | QRIS sengaja tidak aktif secara default: merchant QRIS di acquirer (DANA)
    | sedang on hold, QR terbit tapi ditolak saat di-scan. Aktifkan lagi dengan
    | menambahkan qris ke XENDIT_ENABLED_METHODS setelah Xendit membereskannya.
    |
    */

    'enabled_payment_methods' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string)env('XENDIT_ENABLED_METHODS', 'invoice,virtual_account,ewallet'))
    ), fn($method) => $method !== '')),
];