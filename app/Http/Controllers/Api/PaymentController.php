<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\XenditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected $xenditService;

    public function __construct(XenditService $xenditService)
    {
        $this->xenditService = $xenditService;
    }

    /**
     * Create payment for subscription
     * 
     * ✅ FIX: Instead of always creating a new payment with plan->price,
     * this now REUSES the existing pending payment (created by subscribe())
     * which already has the correct amount based on billing cycle.
     * Only creates a new payment if none exists (direct payment creation flow).
     */
    public function create(Request $request)
    {
        $validated = $request->validate([
            'subscription_id' => 'required|exists:subscriptions,id',
            'payment_method' => 'required|in:invoice,virtual_account,ewallet,qris',
            'bank_code' => 'required_if:payment_method,virtual_account|in:BNI,BRI,MANDIRI,PERMATA,BCA',
            'ewallet_type' => 'required_if:payment_method,ewallet|in:OVO,DANA,LINKAJA,SHOPEEPAY',
        ]);

        $user = $request->user();
        $subscription = Subscription::with('plan', 'household')->findOrFail($validated['subscription_id']);

        // Check authorization
        if ($subscription->household_id !== $user->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$user->isOwner() && !$user->isBillingOwner()) {
            return response()->json(['message' => 'Only owner or billing owner can make payments'], 403);
        }

        // ✅ FIX: Look for existing pending payment from subscribe() flow
        // This payment already has the CORRECT amount (monthly or yearly)
        $payment = Payment::where('subscription_id', $subscription->id)
                          ->where('status', 'pending')
                          ->latest()
                          ->first();

        if ($payment && $payment->payment_gateway_id) {
            // Already has a gateway payment created — return it
            return response()->json([
                'message' => 'Payment already in progress',
                'data' => [
                    'payment' => $this->formatPaymentResponse($payment),
                    'payment_details' => $payment->metadata,
                ],
            ]);
        }

        // If no pending payment exists, create one with correct pricing
        if (!$payment) {
            $price = $this->getSubscriptionPrice($subscription);

            $payment = Payment::create([
                'subscription_id' => $subscription->id,
                'household_id' => $subscription->household_id,
                'user_id' => $user->id,
                'amount' => $price,
                'tax' => 0,
                'total' => $price,
                'currency' => $subscription->plan->currency,
                'payment_method' => $validated['payment_method'],
                'status' => 'pending',
            ]);
        } else {
            // Update the payment method on existing payment
            $payment->update([
                'payment_method' => $validated['payment_method'],
            ]);
        }

        try {
            $result = null;

            switch ($validated['payment_method']) {
                case 'invoice':
                    $result = $this->xenditService->createInvoice($payment, [
                        'name' => $user->name,
                        'email' => $user->email,
                    ]);
                    break;

                case 'virtual_account':
                    $result = $this->xenditService->createVirtualAccount($payment, $validated['bank_code']);
                    break;

                case 'ewallet':
                    $result = $this->xenditService->createEWalletCharge($payment, $validated['ewallet_type']);
                    break;

                case 'qris':
                    $result = $this->xenditService->createQRIS($payment);
                    break;
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment created successfully',
                'data' => [
                    'payment' => $this->formatPaymentResponse($payment->fresh()),
                    'payment_details' => $result,
                ],
            ], 201);

        } catch (\Exception $e) {
            // Don't delete the payment — just log the error
            // The user might retry with a different method
            Log::error('Xendit payment creation failed', [
                'payment_id' => $payment->id,
                'method' => $validated['payment_method'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ✅ NEW: Get correct price for subscription based on billing cycle
     */
    private function getSubscriptionPrice(Subscription $subscription): int
    {
        $plan = $subscription->plan;
        $billingCycle = $subscription->billing_cycle ?? 'monthly';
        $features = $plan->features ?? [];

        if ($billingCycle === 'yearly' && isset($features['price_yearly'])) {
            return (int) $features['price_yearly'];
        }

        if ($billingCycle === 'monthly' && isset($features['price_monthly'])) {
            return (int) $features['price_monthly'];
        }

        return (int) $plan->price;
    }

    /**
     * Get payment status
     */
    public function status(Request $request, Payment $payment)
    {
        if ($payment->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Refresh from Xendit if still pending
        if ($payment->payment_gateway_id && $payment->isPending()) {
            try {
                $xenditInvoice = $this->xenditService->getInvoiceStatus($payment->payment_gateway_id);
                
                if ($xenditInvoice['status'] === 'PAID') {
                    $this->xenditService->handlePaymentSuccess($payment, $xenditInvoice);
                } elseif (in_array($xenditInvoice['status'], ['EXPIRED', 'FAILED'])) {
                    $this->xenditService->handlePaymentFailed($payment, $xenditInvoice);
                }
            } catch (\Exception $e) {
                Log::error('Failed to sync payment status from Xendit: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'payment' => $this->formatPaymentResponse($payment->fresh()),
        ]);
    }

    /**
     * Get payment history
     */
    public function history(Request $request)
    {
        $query = Payment::where('household_id', $request->user()->household_id)
                        ->with(['subscription.plan', 'user']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $limit = $request->input('limit', 20);

        $payments = $query->orderBy('created_at', 'desc')
                          ->paginate($limit);

        return response()->json([
            'success' => true,
            'payments' => $payments->through(function ($payment) {
                return $this->formatPaymentResponse($payment);
            }),
        ]);
    }

    /**
     * Cancel pending payment
     */
    public function cancel(Request $request, Payment $payment)
    {
        if ($payment->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$payment->isPending()) {
            return response()->json(['message' => 'Only pending payments can be canceled'], 400);
        }

        $payment->update([
            'status' => 'expired',
            'metadata' => array_merge($payment->metadata ?? [], [
                'cancelled_by' => 'user',
                'cancelled_at' => now()->toIso8601String(),
            ]),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment canceled successfully',
        ]);
    }

    /**
     * Get payment by token (for redirect page)
     */
    public function getByToken(Request $request)
    {
        $externalId = $request->query('external_id');
        $referenceId = $request->query('reference_id');
        
        $payment = null;
        
        if ($externalId) {
            $payment = Payment::where('payment_token', $externalId)
                ->with(['subscription.plan', 'household'])
                ->first();
        } elseif ($referenceId) {
            $payment = Payment::where('payment_token', $referenceId)
                ->with(['subscription.plan', 'household'])
                ->first();
        }
        
        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found',
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'data' => $payment,
        ]);
    }

    /**
     * Format payment response
     */
    private function formatPaymentResponse(Payment $payment)
    {
        return [
            'id' => $payment->id,
            'subscription_id' => $payment->subscription_id,
            'amount' => $payment->amount,
            'tax' => $payment->tax,
            'total' => $payment->total,
            'formatted_total' => $payment->getFormattedTotal(),
            'currency' => $payment->currency,
            'status' => $payment->status,
            'payment_method' => $payment->payment_method,
            'payment_gateway_id' => $payment->payment_gateway_id,
            'metadata' => $payment->metadata,
            'created_at' => $payment->created_at,
            'paid_at' => $payment->paid_at,
            'subscription' => $payment->subscription ? [
                'id' => $payment->subscription->id,
                'plan_name' => $payment->subscription->plan->name ?? null,
                'billing_cycle' => $payment->subscription->billing_cycle ?? null,
            ] : null,
        ];
    }
}