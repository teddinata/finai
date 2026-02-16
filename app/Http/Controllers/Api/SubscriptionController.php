<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // ✅ FIXED: Jika tidak ada subscription, return free plan
        if (!$subscription) {
            // Get free plan
            $freePlan = \App\Models\Plan::where('slug', 'premium-free')->first();
            
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

        // ✅ Check pending payment
        $pendingPayment = Payment::where('household_id', $household->id)
            ->where('status', 'pending')
            ->latest()
            ->first();

        // ✅ FIXED: Return data even if canceled
        return response()->json([
            'success' => true,
            'subscription' => [
                'id' => $subscription->id,
                'plan' => [
                    'name' => $subscription->plan->name,
                    'slug' => $subscription->plan->slug,
                    'type' => $subscription->plan->type,
                    'features' => $subscription->plan->features,
                ],
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

        DB::beginTransaction();
        try {
            // ✅ AUTO-CANCEL old pending payments
            Payment::where('household_id', $household->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'expired',
                    'metadata' => DB::raw("JSON_SET(COALESCE(metadata, '{}'), '$.auto_canceled', true, '$.reason', 'New subscription created')")
                ]);

            // ✅ Cancel current subscription if exists
            if ($household->currentSubscription) {
                $household->currentSubscription->cancel('Upgraded/downgraded plan');
            }

            // Calculate expiry
            $expiresAt = match($plan->type) {
                'monthly' => now()->addMonth(),
                'yearly' => now()->addYear(),
                'lifetime' => null,
                'free' => null,
            };

            // Create new subscription
            $subscription = Subscription::create([
                'household_id' => $household->id,
                'plan_id' => $plan->id,
                'status' => $plan->isFree() ? 'active' : 'pending',
                'started_at' => now(),
                'expires_at' => $expiresAt,
                'auto_renew' => $plan->isRecurring(),
            ]);

            // Update household
            $household->update(['current_subscription_id' => $subscription->id]);

            // If not free, create payment
            if (!$plan->isFree()) {
                $payment = Payment::create([
                    'subscription_id' => $subscription->id,
                    'household_id' => $household->id,
                    'user_id' => $user->id,
                    'amount' => $plan->price,
                    'tax' => 0,
                    'total' => $plan->price,
                    'currency' => $plan->currency,
                    'payment_method' => 'pending', // ✅ Will be set when user chooses method
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

        } catch (\Exception $e) {
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

        // Don't allow canceling free plan
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