<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\XenditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected $xenditService;

    public function __construct(XenditService $xenditService)
    {
        $this->xenditService = $xenditService;
    }

    /**
     * Create payment
     */
    public function create(Request $request)
    {
        $request->validate([
            'subscription_id' => 'required|exists:subscriptions,id',
            'payment_method' => 'required|in:invoice,virtual_account,ewallet,qris',
            'bank_code' => 'required_if:payment_method,virtual_account|in:BNI,BRI,MANDIRI,PERMATA,BCA',
            'ewallet_type' => 'required_if:payment_method,ewallet|in:OVO,DANA,LINKAJA,SHOPEEPAY',
        ]);

        try {
            DB::beginTransaction();

            $subscription = Subscription::findOrFail($request->subscription_id);
            $user = Auth::user();
            $household = $user->household;

            // Validate subscription belongs to user's household
            if ($subscription->household_id !== $household->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to subscription',
                ], 403);
            }

            // Create payment record
            $payment = Payment::create([
                'subscription_id' => $subscription->id,
                'household_id' => $household->id,
                'user_id' => $user->id,
                'amount' => $subscription->plan->price,
                'tax' => 0,
                'total' => $subscription->plan->price,
                'currency' => 'IDR',
                'payment_method' => $request->payment_method,
                'status' => 'pending',
            ]);

            // Route to appropriate payment method
            $result = match ($request->payment_method) {
                'invoice' => $this->createInvoicePayment($payment, $user),
                'virtual_account' => $this->createVAPayment($payment, $request->bank_code),
                'ewallet' => $this->createEWalletPayment($payment, $request->ewallet_type),
                'qris' => $this->createQRISPayment($payment),
                default => throw new \Exception('Invalid payment method'),
            };

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment created successfully',
                'data' => [
                    'payment' => $payment->fresh(),
                    'payment_details' => $result,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
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
     * Create Invoice Payment
     */
    protected function createInvoicePayment(Payment $payment, $user)
    {
        return $this->xenditService->createInvoice($payment, [
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }

    /**
     * Create Virtual Account Payment
     */
    protected function createVAPayment(Payment $payment, string $bankCode)
    {
        return $this->xenditService->createVirtualAccount($payment, $bankCode);
    }

    /**
     * Create E-Wallet Payment
     */
    protected function createEWalletPayment(Payment $payment, string $ewalletType)
    {
        return $this->xenditService->createEWalletCharge($payment, $ewalletType);
    }

    /**
     * Create QRIS Payment
     */
    protected function createQRISPayment(Payment $payment)
    {
        return $this->xenditService->createQRIS($payment);
    }

    /**
     * Get payment history
     */
    public function history(Request $request)
    {
        $user = Auth::user();
        
        $payments = Payment::where('household_id', $user->household_id)
            ->with(['subscription.plan', 'user'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $payments,
        ]);
    }

    /**
     * Get payment status
     */
    public function status(Payment $payment)
    {
        $user = Auth::user();

        if ($payment->household_id !== $user->household_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'payment' => $payment->load(['subscription.plan']),
        ]);
    }

    /**
     * Get payment by token (untuk redirect page)
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
     * Cancel payment
     */
    public function cancel(Payment $payment)
    {
        $user = Auth::user();

        if ($payment->household_id !== $user->household_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        if ($payment->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending payments can be cancelled',
            ], 400);
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
            'message' => 'Payment cancelled',
        ]);
    }
}