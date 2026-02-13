<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;

class CheckExpiredSubscriptions extends Command
{
    protected $signature = 'subscriptions:check-expired';
    protected $description = 'Check and update expired subscriptions';

    public function handle()
    {
        $this->info('Checking for expired subscriptions...');

        $expiredCount = Subscription::where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->where('auto_renew', false)
            ->update(['status' => 'expired']);

        $this->info("Updated {$expiredCount} expired subscriptions");

        // Check trial expiry
        $trialExpiredCount = Subscription::where('status', 'trial')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->update(['status' => 'expired']);

        $this->info("Updated {$trialExpiredCount} expired trials");

        return Command::SUCCESS;
    }
}