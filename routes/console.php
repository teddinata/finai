<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Check expired subscriptions every hour
Schedule::command('subscriptions:check-expired')->hourly();

// Process auto-renewals daily at 9 AM
Schedule::command('subscriptions:auto-renew')->dailyAt('09:00');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
