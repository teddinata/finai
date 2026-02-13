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
    'webhook_url' => env('APP_URL') . '/api/webhooks/xendit',

    /*
    |--------------------------------------------------------------------------
    | Redirect URLs
    |--------------------------------------------------------------------------
    |
    | URLs to redirect users after payment success/failure
    |
    */

    'success_redirect_url' => env('XENDIT_SUCCESS_URL', env('APP_URL') . '/payment/success'),
    'failure_redirect_url' => env('XENDIT_FAILURE_URL', env('APP_URL') . '/payment/failed'),

    /*
    |--------------------------------------------------------------------------
    | Payment Settings
    |--------------------------------------------------------------------------
    */

    'invoice_duration' => 86400, // 24 hours in seconds
    'currency' => 'IDR',
];