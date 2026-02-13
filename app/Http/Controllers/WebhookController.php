<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\XenditService;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    protected $xenditService;

    public function __construct(XenditService $xenditService)
    {
        $this->xenditService = $xenditService;
    }

    /**
     * Handle Xendit webhooks
     */
    public function xendit(Request $request)
    {
        // Log incoming webhook for debugging
        Log::info('Xendit Webhook Received', [
            'headers' => $request->headers->all(),
            'body' => $request->all(),
        ]);

        // Verify webhook token
        $callbackToken = $request->header('x-callback-token');
        
        if (!$this->xenditService->verifyWebhookToken($callbackToken)) {
            Log::warning('Invalid Xendit webhook token', [
                'token_received' => $callbackToken,
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $data = $request->all();
            
            // Determine webhook type
            if (isset($data['external_id']) && str_starts_with($data['external_id'], 'PAYMENT-')) {
                // Invoice webhook
                return $this->handleInvoiceWebhook($data);
            } elseif (isset($data['external_id']) && str_starts_with($data['external_id'], 'VA-')) {
                // Virtual Account webhook
                return $this->handleVirtualAccountWebhook($data);
            } elseif (isset($data['reference_id']) && str_starts_with($data['reference_id'], 'EWALLET-')) {
                // E-Wallet webhook
                return $this->handleEWalletWebhook($data);
            } elseif (isset($data['reference_id']) && str_starts_with($data['reference_id'], 'QRIS-')) {
                // QRIS webhook
                return $this->handleQRISWebhook($data);
            }

            Log::warning('Unknown webhook type', ['data' => $data]);
            return response()->json(['error' => 'Unknown webhook type'], 400);

        } catch (\Exception $e) {
            Log::error('Webhook processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Handle Invoice/Payment Link webhook
     */
    protected function handleInvoiceWebhook(array $data)
    {
        $externalId = $data['external_id']; // PAYMENT-123
        $paymentId = str_replace('PAYMENT-', '', $externalId);
        
        $payment = Payment::find($paymentId);
        
        if (!$payment) {
            Log::error('Payment not found for invoice webhook', [
                'external_id' => $externalId,
                'payment_id' => $paymentId,
            ]);
            return response()->json(['error' => 'Payment not found'], 404);
        }

        $status = strtolower($data['status']);

        Log::info('Processing invoice webhook', [
            'payment_id' => $payment->id,
            'status' => $status,
            'xendit_id' => $data['id'],
        ]);

        switch ($status) {
            case 'paid':
            case 'settled':
                $this->xenditService->handlePaymentSuccess($payment, $data);
                Log::info('Payment marked as paid', ['payment_id' => $payment->id]);
                break;

            case 'expired':
                $payment->update([
                    'status' => 'expired',
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'expired_at' => $data['updated'] ?? now(),
                    ]),
                ]);
                Log::info('Payment marked as expired', ['payment_id' => $payment->id]);
                break;

            case 'failed':
                $this->xenditService->handlePaymentFailed($payment, $data);
                Log::info('Payment marked as failed', ['payment_id' => $payment->id]);
                break;

            default:
                Log::info('Invoice status update', [
                    'payment_id' => $payment->id,
                    'status' => $status,
                ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Handle Virtual Account webhook
     */
    protected function handleVirtualAccountWebhook(array $data)
    {
        $externalId = $data['external_id']; // VA-123
        $paymentId = str_replace('VA-', '', $externalId);
        
        $payment = Payment::find($paymentId);
        
        if (!$payment) {
            Log::error('Payment not found for VA webhook', [
                'external_id' => $externalId,
                'payment_id' => $paymentId,
            ]);
            return response()->json(['error' => 'Payment not found'], 404);
        }

        // VA callback only comes when paid
        if (isset($data['callback_virtual_account_id'])) {
            Log::info('VA Payment received', [
                'payment_id' => $payment->id,
                'va_id' => $data['id'],
                'amount' => $data['amount'],
            ]);

            $this->xenditService->handlePaymentSuccess($payment, [
                'id' => $data['id'],
                'payment_channel' => 'VIRTUAL_ACCOUNT',
                'paid_amount' => $data['amount'],
                'payment_id' => $data['payment_id'] ?? null,
                'bank_code' => $data['bank_code'] ?? null,
            ]);

            return response()->json(['success' => true]);
        }

        return response()->json(['success' => true, 'message' => 'VA created']);
    }

    /**
     * Handle E-Wallet webhook
     */
    protected function handleEWalletWebhook(array $data)
    {
        $referenceId = $data['reference_id']; // EWALLET-123
        $paymentId = str_replace('EWALLET-', '', $referenceId);
        
        $payment = Payment::find($paymentId);
        
        if (!$payment) {
            Log::error('Payment not found for E-Wallet webhook', [
                'reference_id' => $referenceId,
                'payment_id' => $paymentId,
            ]);
            return response()->json(['error' => 'Payment not found'], 404);
        }

        $status = strtolower($data['status'] ?? '');

        Log::info('Processing E-Wallet webhook', [
            'payment_id' => $payment->id,
            'status' => $status,
            'charge_id' => $data['id'],
        ]);

        switch ($status) {
            case 'succeeded':
            case 'paid':
                $this->xenditService->handlePaymentSuccess($payment, [
                    'id' => $data['id'],
                    'payment_channel' => 'EWALLET',
                    'paid_amount' => $data['charge_amount'],
                    'ewallet_type' => $data['channel_code'] ?? 'unknown',
                ]);
                Log::info('E-Wallet payment successful', ['payment_id' => $payment->id]);
                break;

            case 'failed':
                $this->xenditService->handlePaymentFailed($payment, [
                    'failure_code' => $data['failure_code'] ?? 'unknown',
                ]);
                Log::info('E-Wallet payment failed', ['payment_id' => $payment->id]);
                break;

            default:
                Log::info('E-Wallet status update', [
                    'payment_id' => $payment->id,
                    'status' => $status,
                ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Handle QRIS webhook
     */
    protected function handleQRISWebhook(array $data)
    {
        $referenceId = $data['reference_id']; // QRIS-123
        $paymentId = str_replace('QRIS-', '', $referenceId);
        
        $payment = Payment::find($paymentId);
        
        if (!$payment) {
            Log::error('Payment not found for QRIS webhook', [
                'reference_id' => $referenceId,
                'payment_id' => $paymentId,
            ]);
            return response()->json(['error' => 'Payment not found'], 404);
        }

        $status = strtolower($data['status'] ?? '');

        Log::info('Processing QRIS webhook', [
            'payment_id' => $payment->id,
            'status' => $status,
            'qr_id' => $data['id'],
        ]);

        switch ($status) {
            case 'completed':
            case 'paid':
                $this->xenditService->handlePaymentSuccess($payment, [
                    'id' => $data['id'],
                    'payment_channel' => 'QRIS',
                    'paid_amount' => $data['amount'],
                ]);
                Log::info('QRIS payment successful', ['payment_id' => $payment->id]);
                break;

            case 'failed':
            case 'expired':
                $payment->update([
                    'status' => 'expired',
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'failure_reason' => $data['failure_code'] ?? 'expired',
                    ]),
                ]);
                Log::info('QRIS payment failed/expired', ['payment_id' => $payment->id]);
                break;

            default:
                Log::info('QRIS status update', [
                    'payment_id' => $payment->id,
                    'status' => $status,
                ]);
        }

        return response()->json(['success' => true]);
    }
}