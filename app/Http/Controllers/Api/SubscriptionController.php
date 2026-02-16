<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    /**
     * Get current subscription
     */
    public function current(Request $request)
    {
        $household = $request->user()->household;

        if (!$household) {
            return response()->json([
                'success' => false,
                'message' => 'No household found',
            ], 404);
        }

        $subscription = $household->currentSubscription()
            ->with('plan')
            ->first();

        // Jika tidak ada subscription, return free plan
        if (!$subscription) {
            $freePlan = Plan::where('slug', 'premium-free')->first();

            return response()->json([
                'success' => true,
                'subscription' => null,
                'free_plan' => $freePlan ? [
                    'name' => $freePlan->name,
                    'slug' => $freePlan->slug,
                    'type' => $freePlan->type,
                    'features' => $freePlan->features,
                ] : null,
                'pending_payment' => null,
            ]);
        }

        // ✅ FIX: If subscription is already active, auto-expire orphaned pending payments
        $pendingPayment = null;

        if ($subscription->status === 'active') {
            $orphanedCount = Payment::where('household_id', $household->id)
                ->where('subscription_id', $subscription->id)
                ->where('status', 'pending')
                ->count();

            if ($orphanedCount > 0) {
                Payment::where('household_id', $household->id)
                    ->where('subscription_id', $subscription->id)
                    ->where('status', 'pending')
                    ->update([
                    'status' => 'expired',
                    'metadata' => DB::raw("JSON_SET(COALESCE(metadata, '{}'), '$.auto_expired', true, '$.reason', 'Subscription already active')")
                ]);

                Log::info('Auto-expired orphaned pending payments', [
                    'household_id' => $household->id,
                    'subscription_id' => $subscription->id,
                    'count' => $orphanedCount,
                ]);
            }
        }
        else {
            // Only show pending payment for the CURRENT subscription
            $pendingPayment = Payment::where('household_id', $household->id)
                ->where('subscription_id', $subscription->id)
                ->where('status', 'pending')
                ->latest()
                ->first();
        }

        // ✅ FIX: If subscription is pending, return free plan as effective features
        $effectivePlan = $subscription->plan;
        if ($subscription->status === 'pending') {
            $freePlan = Plan::where('slug', 'premium-free')->first();
            if ($freePlan) {
                $effectivePlan = $freePlan;
            }
        }

        return response()->json([
            'success' => true,
            'subscription' => [
                'id' => $subscription->id,
                'plan' => [
                    'name' => $subscription->plan->name,
                    'slug' => $subscription->plan->slug,
                    'type' => $subscription->plan->type,
                    'features' => $effectivePlan->features, // ✅ Use effective plan features
                ],
                'billing_cycle' => $subscription->billing_cycle,
                'status' => $subscription->status,
                'started_at' => $subscription->started_at,
                'expires_at' => $subscription->expires_at,
                'trial_ends_at' => $subscription->trial_ends_at,
                'auto_renew' => $subscription->auto_renew,
                'days_until_expiry' => $subscription->daysUntilExpiry(),
                'canceled_at' => $subscription->canceled_at,
            ],
            'pending_payment' => $pendingPayment,
        ]);
    }

    /**
     * Subscribe to a plan
     */
    public function subscribe(Request $request, Plan $plan)
    {
        if (!$plan->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Plan not available',
            ], 400);
        }

        $user = $request->user();
        $household = $user->household;

        if (!$user->isOwner() && !$user->isBillingOwner()) {
            return response()->json([
                'success' => false,
                'message' => 'Only owner or billing owner can manage subscription',
            ], 403);
        }

        // ✅ FIX: Accept billing_cycle from frontend
        $billingCycle = $request->input('billing_cycle', 'monthly');

        // Validate billing cycle
        if (!in_array($billingCycle, ['monthly', 'yearly'])) {
            $billingCycle = 'monthly';
        }

        // ✅ FIX: Calculate correct price based on billing cycle
        $price = $this->calculatePrice($plan, $billingCycle);

        DB::beginTransaction();
        try {
            // Auto-cancel all old pending payments
            Payment::where('household_id', $household->id)
                ->where('status', 'pending')
                ->update([
                'status' => 'expired',
                'metadata' => DB::raw("JSON_SET(COALESCE(metadata, '{}'), '$.auto_canceled', true, '$.reason', 'New subscription created')")
            ]);

            // Cancel current subscription if exists
            if ($household->currentSubscription) {
                $household->currentSubscription->cancel('Upgraded/downgraded plan');
            }

            // ✅ FIX: Calculate expiry based on billing_cycle, not plan->type
            $expiresAt = match ($billingCycle) {
                    'yearly' => now()->addYear(),
                    'monthly' => now()->addMonth(),
                    default => $plan->type === 'lifetime' ? null : ($plan->type === 'free' ? null : now()->addMonth()),
                };

            // For free/lifetime plans, override
            if ($plan->isFree()) {
                $billingCycle = 'free';
                $expiresAt = null;
            }
            elseif ($plan->type === 'lifetime') {
                $billingCycle = 'lifetime';
                $expiresAt = null;
            }

            // Create new subscription
            $subscription = Subscription::create([
                'household_id' => $household->id,
                'plan_id' => $plan->id,
                'billing_cycle' => $billingCycle,
                'status' => $plan->isFree() ? 'active' : 'pending',
                'started_at' => now(),
                'expires_at' => $expiresAt,
                'auto_renew' => $plan->isRecurring(),
            ]);

            // Update household
            $household->update(['current_subscription_id' => $subscription->id]);

            // If not free, create payment with CORRECT price
            if (!$plan->isFree()) {
                $payment = Payment::create([
                    'subscription_id' => $subscription->id,
                    'household_id' => $household->id,
                    'user_id' => $user->id,
                    'amount' => $price,
                    'tax' => 0,
                    'total' => $price,
                    'currency' => $plan->currency,
                    'payment_method' => 'pending',
                    'status' => 'pending',
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Subscription created, please complete payment',
                    'subscription' => $subscription->load('plan'),
                    'payment' => [
                        'id' => $payment->id,
                        'amount' => $payment->total,
                        'formatted_amount' => 'Rp ' . number_format($payment->total, 0, ',', '.'),
                        'status' => $payment->status,
                        'billing_cycle' => $billingCycle,
                    ],
                    'next_step' => 'choose_payment_method',
                ], 201);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Subscription activated',
                'subscription' => $subscription->load('plan'),
            ], 201);

        }
        catch (\Exception $e) {
            DB::rollBack();

            Log::error('Subscription failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Subscription failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ✅ NEW: Calculate correct price based on billing cycle
     */
    protected function calculatePrice(Plan $plan, string $billingCycle): int
    {
        if ($plan->isFree()) {
            return 0;
        }

        $features = $plan->features ?? [];

        if ($billingCycle === 'yearly' && isset($features['price_yearly'])) {
            return (int)$features['price_yearly'];
        }

        if ($billingCycle === 'monthly' && isset($features['price_monthly'])) {
            return (int)$features['price_monthly'];
        }

        // Fallback to base price
        return (int)$plan->price;
    }

    /**
     * Cancel subscription
     */
    public function cancel(Request $request)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $household = $user->household;

        if (!$user->isOwner() && !$user->isBillingOwner()) {
            return response()->json([
                'message' => 'Only owner or billing owner can cancel subscription',
            ], 403);
        }

        $subscription = $household->currentSubscription;

        if (!$subscription) {
            return response()->json([
                'message' => 'No active subscription',
            ], 404);
        }

        if ($subscription->plan->isFree()) {
            return response()->json([
                'message' => 'Cannot cancel free plan',
            ], 400);
        }

        $subscription->cancel($validated['reason'] ?? null);

        return response()->json([
            'message' => 'Subscription canceled successfully',
            'subscription' => $subscription->fresh(),
        ]);
    }

    /**
     * Enable auto-renewal
     */
    public function enableAutoRenew(Request $request)
    {
        $subscription = $request->user()->household->currentSubscription;

        if (!$subscription) {
            return response()->json(['message' => 'No active subscription'], 404);
        }

        if ($subscription->plan->type === 'lifetime') {
            return response()->json(['message' => 'Lifetime plan does not need renewal'], 400);
        }

        $subscription->update(['auto_renew' => true]);

        return response()->json([
            'message' => 'Auto-renewal enabled',
            'subscription' => $subscription->fresh(),
        ]);
    }

    /**
     * Disable auto-renewal
     */
    public function disableAutoRenew(Request $request)
    {
        $subscription = $request->user()->household->currentSubscription;

        if (!$subscription) {
            return response()->json(['message' => 'No active subscription'], 404);
        }

        $subscription->update(['auto_renew' => false]);

        return response()->json([
            'message' => 'Auto-renewal disabled',
            'subscription' => $subscription->fresh(),
        ]);
    }
}