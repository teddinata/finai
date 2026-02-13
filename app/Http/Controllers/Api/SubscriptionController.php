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
                'message' => 'No household found',
            ], 404);
        }

        $subscription = $household->currentSubscription()
                                  ->with('plan')
                                  ->first();

        if (!$subscription) {
            return response()->json([
                'message' => 'No active subscription',
            ], 404);
        }

        return response()->json([
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
            ],
        ]);
    }

    /**
     * Subscribe to a plan
     */
    public function subscribe(Request $request, Plan $plan)
    {
        if (!$plan->is_active) {
            return response()->json([
                'message' => 'Plan not available',
            ], 400);
        }

        $user = $request->user();
        $household = $user->household;

        // Only owner or billing owner can subscribe
        if (!$user->isOwner() && !$user->isBillingOwner()) {
            return response()->json([
                'message' => 'Only owner or billing owner can manage subscription',
            ], 403);
        }

        DB::beginTransaction();
        try {
            // Cancel current subscription if exists
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
                    'tax' => 0, // Calculate tax jika perlu
                    'total' => $plan->price,
                    'currency' => $plan->currency,
                    'payment_method' => 'midtrans',
                    'status' => 'pending',
                ]);

                DB::commit();

                return response()->json([
                    'message' => 'Subscription created, please complete payment',
                    'subscription' => $subscription->load('plan'),
                    'payment' => [
                        'id' => $payment->id,
                        'amount' => $payment->total,
                        'formatted_amount' => $payment->getFormattedTotal(),
                        'status' => $payment->status,
                    ],
                    'next_step' => 'create_payment',
                ], 201);
            }

            DB::commit();

            return response()->json([
                'message' => 'Subscription activated',
                'subscription' => $subscription->load('plan'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
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