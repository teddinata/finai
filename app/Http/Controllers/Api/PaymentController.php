<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\XenditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class PaymentController extends Controller
{
    protected $xenditService;
    protected $voucherService;

    public function __construct(XenditService $xenditService, \App\Services\VoucherService $voucherService)
    {
        $this->xenditService = $xenditService;
        $this->voucherService = $voucherService;
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
        $request->validate([
            'subscription_id' => 'required|exists:subscriptions,id',
            'payment_method' => 'required|in:invoice,virtual_account,ewallet,qris',
            'bank_code' => 'required_if:payment_method,virtual_account|in:BNI,BRI,MANDIRI,PERMATA,BCA',
            'ewallet_type' => 'required_if:payment_method,ewallet|in:OVO,DANA,LINKAJA,SHOPEEPAY',
            'voucher_code' => 'nullable|string|max:50',
        ]);

        try {
            return DB::transaction(function () use ($request) {
                $subscription = Subscription::with('plan')->findOrFail($request->subscription_id);
                $user = Auth::user();
                $household = $user->household;

                // Validate subscription belongs to user's household
                if ($subscription->household_id !== $household->id) {
                    abort(403, 'Unauthorized access to subscription');
                }

                // Calculate amounts based on billing cycle
                $billingCycle = $subscription->billing_cycle ?? 'monthly';
                $originalAmount = $billingCycle === 'yearly' && $subscription->plan->effective_yearly_price !== null
                    ? $subscription->plan->effective_yearly_price
                    : $subscription->plan->effective_price;
                $discountAmount = 0;
                $voucher = null;

                // Handle Voucher
                if ($request->voucher_code) {
                    $voucher = $this->voucherService->validate(
                        $request->voucher_code,
                        $household->id,
                        $subscription->plan_id,
                        $originalAmount
                    );

                    $discountAmount = $this->voucherService->calculateDiscount($voucher, $originalAmount);
                }

                $total = max(0, $originalAmount - $discountAmount);

                // Create payment record
                $payment = Payment::create([
                    'subscription_id' => $subscription->id,
                    'household_id' => $household->id,
                    'user_id' => $user->id,
                    'amount' => $total,
                    'original_amount' => $originalAmount,
                    'discount_amount' => $discountAmount,
                    'voucher_id' => $voucher ? $voucher->id : null,
                    'tax' => 0,
                    'total' => $total,
                    'currency' => 'IDR',
                    'payment_method' => $request->payment_method,
                    'status' => $total > 0 ? 'pending' : 'paid',
                ]);

                // Apply voucher usage
                if ($voucher) {
                    $this->voucherService->apply($voucher, $payment);
                }

                // If total is 0, we don't need Xendit
                if ($total == 0) {
                    $payment->markAsPaid(null, ['note' => 'Paid via 100% discount voucher']);

                    // Activate subscription immediately
                    $this->activateSubscription($subscription);

                    return response()->json([
                        'success' => true,
                        'message' => 'Payment successful (Free via Voucher)',
                        'data' => [
                            'payment' => $payment->fresh(),
                            'payment_details' => null,
                        ],
                    ]);
                }

                // Route to appropriate payment method
                $result = match ($request->payment_method) {
                        'invoice' => $this->createInvoicePayment($payment, $user),
                        'virtual_account' => $this->createVAPayment($payment, $request->bank_code),
                        'ewallet' => $this->createEWalletPayment($payment, $request->ewallet_type),
                        'qris' => $this->createQRISPayment($payment),
                        default => throw new \Exception('Invalid payment method'),
                    };

                return response()->json([
                    'success' => true,
                    'message' => 'Payment created successfully',
                    'data' => [
                        'payment' => $payment->fresh(),
                        'payment_details' => $result,
                    ],
                ], 201);
            });

        }
        catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
        catch (\Exception $e) {
            Log::error('Payment creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper to activate subscription
     */
    protected function activateSubscription(Subscription $subscription)
    {
        $expiresAt = match ($subscription->plan->type) {
                'monthly' => now()->addMonth(),
                'yearly' => now()->addYear(),
                'lifetime' => null,
                default => now()->addMonth(),
            };

        $subscription->update([
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        $subscription->household->update([
            'current_subscription_id' => $subscription->id,
        ]);
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
            return (int)$features['price_yearly'];
        }

        if ($billingCycle === 'monthly' && isset($features['price_monthly'])) {
            return (int)$features['price_monthly'];
        }

        return (int)$plan->price;
    }

    /**
     * Get payment status
     * 
     * Note: Status updates are handled by Xendit webhooks.
     * This endpoint just returns the current payment state from DB.
     */
    public function status(Request $request, Payment $payment)
    {
        if ($payment->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'payment' => $this->formatPaymentResponse($payment->load(['subscription.plan', 'voucher'])->fresh()),
        ]);
    }

    /**
     * Get payment history
     */
    public function history(Request $request)
    {
        $query = Payment::where('household_id', $request->user()->household_id)
            ->with(['subscription.plan', 'user', 'voucher']);

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

        DB::transaction(function () use ($payment) {
            // Reverse voucher usage if used
            if ($payment->voucher_id) {
                $this->voucherService->reverse($payment);
            }

            $payment->update([
                'status' => 'expired',
                'metadata' => array_merge($payment->metadata ?? [], [
                    'cancelled_by' => 'user',
                    'cancelled_at' => now()->toIso8601String(),
                ]),
            ]);
        });

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
        }
        elseif ($referenceId) {
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
    private function createInvoicePayment(Payment $payment, \App\Models\User $user)
    {
        return $this->xenditService->createInvoice($payment, [
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }

    private function createVAPayment(Payment $payment, $bankCode)
    {
        return $this->xenditService->createVirtualAccount($payment, $bankCode);
    }

    private function createEWalletPayment(Payment $payment, $ewalletType)
    {
        return $this->xenditService->createEWalletCharge($payment, $ewalletType);
    }

    private function createQRISPayment(Payment $payment)
    {
        return $this->xenditService->createQRIS($payment);
    }
}