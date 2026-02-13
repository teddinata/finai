<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\XenditService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    protected $xenditService;

    public function __construct(XenditService $xenditService)
    {
        $this->xenditService = $xenditService;
    }

    /**
     * Create payment for subscription
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

        // Check if there's pending payment
        $existingPayment = Payment::where('subscription_id', $subscription->id)
                                  ->where('status', 'pending')
                                  ->first();

        if ($existingPayment) {
            return response()->json([
                'message' => 'There is already a pending payment',
                'payment' => $this->formatPaymentResponse($existingPayment),
            ], 400);
        }

        // Create payment record
        $payment = Payment::create([
            'subscription_id' => $subscription->id,
            'household_id' => $subscription->household_id,
            'user_id' => $user->id,
            'amount' => $subscription->plan->price,
            'tax' => 0, // Calculate if needed
            'total' => $subscription->plan->price,
            'currency' => $subscription->plan->currency,
            'payment_method' => 'xendit',
            'status' => 'pending',
        ]);

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
                    // Implement QRIS if needed
                    return response()->json(['message' => 'QRIS not implemented yet'], 501);
            }

            return response()->json([
                'message' => 'Payment created successfully',
                'payment' => $this->formatPaymentResponse($payment->fresh()),
                'payment_data' => $result,
            ], 201);

        } catch (\Exception $e) {
            $payment->delete();
            
            return response()->json([
                'message' => 'Failed to create payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get payment status
     */
    public function status(Request $request, Payment $payment)
    {
        // Check authorization
        if ($payment->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Refresh from Xendit
        if ($payment->payment_gateway_id && $payment->isPending()) {
            try {
                $xenditInvoice = $this->xenditService->getInvoiceStatus($payment->payment_gateway_id);
                
                // Update status based on Xendit
                if ($xenditInvoice['status'] === 'PAID') {
                    $this->xenditService->handlePaymentSuccess($payment, $xenditInvoice);
                } elseif (in_array($xenditInvoice['status'], ['EXPIRED', 'FAILED'])) {
                    $this->xenditService->handlePaymentFailed($payment, $xenditInvoice);
                }
            } catch (\Exception $e) {
                // Log error but don't fail the request
                \Log::error('Failed to sync payment status from Xendit: ' . $e->getMessage());
            }
        }

        return response()->json([
            'payment' => $this->formatPaymentResponse($payment->fresh()),
        ]);
    }

    /**
     * Get payment history
     */
    public function history(Request $request)
    {
        $payments = Payment::where('household_id', $request->user()->household_id)
                          ->with(['subscription.plan', 'user'])
                          ->orderBy('created_at', 'desc')
                          ->paginate(20);

        return response()->json([
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
        // Check authorization
        if ($payment->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$payment->isPending()) {
            return response()->json(['message' => 'Only pending payments can be canceled'], 400);
        }

        $payment->update(['status' => 'expired']);

        return response()->json([
            'message' => 'Payment canceled successfully',
        ]);
    }

    /**
     * Format payment response
     */
    private function formatPaymentResponse(Payment $payment)
    {
        return [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'tax' => $payment->tax,
            'total' => $payment->total,
            'formatted_total' => $payment->getFormattedTotal(),
            'currency' => $payment->currency,
            'status' => $payment->status,
            'payment_method' => $payment->payment_method,
            'payment_url' => $payment->snap_token,
            'va_number' => $payment->metadata['va_number'] ?? null,
            'bank_code' => $payment->metadata['bank_code'] ?? null,
            'created_at' => $payment->created_at,
            'paid_at' => $payment->paid_at,
            'subscription' => $payment->subscription ? [
                'id' => $payment->subscription->id,
                'plan_name' => $payment->subscription->plan->name,
            ] : null,
        ];
    }
}