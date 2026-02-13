<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;
use App\Models\Payment;
use App\Services\XenditService;

class AutoRenewSubscriptions extends Command
{
    protected $signature = 'subscriptions:auto-renew';
    protected $description = 'Process auto-renewal for subscriptions';

    protected $xenditService;

    public function __construct(XenditService $xenditService)
    {
        parent::__construct();
        $this->xenditService = $xenditService;
    }

    public function handle()
    {
        $this->info('Processing auto-renewals...');

        // Find subscriptions expiring in next 3 days with auto_renew enabled
        $subscriptions = Subscription::where('status', 'active')
            ->where('auto_renew', true)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays(3)])
            ->with(['household', 'plan'])
            ->get();

        $renewedCount = 0;

        foreach ($subscriptions as $subscription) {
            try {
                // Check if there's already a pending payment
                $existingPayment = Payment::where('subscription_id', $subscription->id)
                    ->where('status', 'pending')
                    ->exists();

                if ($existingPayment) {
                    continue;
                }

                // Get billing owner
                $billingOwner = $subscription->household->users()
                    ->where('is_billing_owner', true)
                    ->first();

                if (!$billingOwner) {
                    $billingOwner = $subscription->household->owner();
                }

                // Create payment
                $payment = Payment::create([
                    'subscription_id' => $subscription->id,
                    'household_id' => $subscription->household_id,
                    'user_id' => $billingOwner->id,
                    'amount' => $subscription->plan->price,
                    'tax' => 0,
                    'total' => $subscription->plan->price,
                    'currency' => $subscription->plan->currency,
                    'payment_method' => 'xendit',
                    'status' => 'pending',
                ]);

                // Create Xendit invoice
                $this->xenditService->createInvoice($payment, [
                    'name' => $billingOwner->name,
                    'email' => $billingOwner->email,
                ]);

                $renewedCount++;
                
                $this->info("Created renewal invoice for subscription #{$subscription->id}");

            } catch (\Exception $e) {
                $this->error("Failed to renew subscription #{$subscription->id}: {$e->getMessage()}");
            }
        }

        $this->info("Created {$renewedCount} renewal invoices");

        return Command::SUCCESS;
    }
}