<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\XenditService;

class XenditServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(XenditService::class, function ($app) {
            return new XenditService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}