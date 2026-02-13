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
     * 
     * Supports both:
     * - V1 webhooks (flat payload with external_id)
     * - V2 webhooks (event-based with data nested inside 'data' key)
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

        // Debug: log token comparison (remove after fixing)
        Log::info('Webhook token debug', [
            'received' => $callbackToken,
            'expected' => config('xendit.webhook_token'),
            'match' => $callbackToken === config('xendit.webhook_token'),
        ]);

        if (!$this->xenditService->verifyWebhookToken($callbackToken)) {
            Log::warning('Invalid Xendit webhook token', [
                'token_received' => $callbackToken,
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $payload = $request->all();

            // Detect webhook version and route accordingly
            // V2: has 'event' and 'data' keys
            if (isset($payload['event']) && isset($payload['data'])) {
                return $this->handleV2Webhook($payload);
            }

            // V1: flat payload — detect by external_id prefix
            return $this->handleV1Webhook($payload);

        }
        catch (\Exception $e) {
            Log::error('Webhook processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    // =========================================================================
    //  V2 WEBHOOK HANDLER (Event-based: ewallet.capture, qr.payment, etc.)
    // =========================================================================

    /**
     * Handle V2 event-based webhooks
     * 
     * Format: { "event": "ewallet.capture", "data": { ... }, "business_id": "..." }
     */
    protected function handleV2Webhook(array $payload)
    {
        $event = $payload['event'];
        $data = $payload['data'];

        Log::info('Processing V2 webhook', [
            'event' => $event,
            'data_id' => $data['id'] ?? 'unknown',
        ]);

        // Route by event type
        return match (true) {
                // Invoice events
                str_starts_with($event, 'invoice.') => $this->handleInvoiceWebhook($data),

                // E-Wallet events
                str_starts_with($event, 'ewallet.') => $this->handleEWalletWebhook($data),

                // QRIS events
                str_starts_with($event, 'qr.') => $this->handleQRISWebhook($data),

                // Virtual Account events
                str_starts_with($event, 'fva.') ||
                str_starts_with($event, 'virtual_account.') => $this->handleVirtualAccountWebhook($data),

                // Payment Request events (Xendit V2 unified)
                str_starts_with($event, 'payment.') => $this->handlePaymentRequestWebhook($data),

                default => $this->handleUnknownWebhook($event, $data),
            };
    }

    // =========================================================================
    //  V1 WEBHOOK HANDLER (Flat payload with external_id prefix)
    // =========================================================================

    /**
     * Handle V1 flat webhooks (detected by external_id prefix)
     */
    protected function handleV1Webhook(array $data)
    {
        $externalId = $data['external_id'] ?? null;
        $referenceId = $data['reference_id'] ?? null;

        Log::info('Processing V1 webhook', [
            'external_id' => $externalId,
            'reference_id' => $referenceId,
        ]);

        // Route by prefix
        return match (true) {
                // Our custom prefixes from PaymentController
                $externalId && str_starts_with($externalId, 'PAYMENT-') => $this->handleInvoiceWebhook($data),
                $externalId && str_starts_with($externalId, 'VA-') => $this->handleVirtualAccountWebhook($data),
                $referenceId && str_starts_with($referenceId, 'EWALLET-') => $this->handleEWalletWebhook($data),
                $referenceId && str_starts_with($referenceId, 'QRIS-') => $this->handleQRISWebhook($data),

                // Fallback: detect by payload structure (for Xendit test webhooks)
                isset($data['callback_virtual_account_id']) => $this->handleVirtualAccountWebhook($data),
                isset($data['payment_channel']) && isset($data['paid_amount']) => $this->handleInvoiceWebhook($data),

                default => $this->handleUnknownWebhook('v1_unknown', $data),
            };
    }

    // =========================================================================
    //  PAYMENT HANDLERS
    // =========================================================================

    /**
     * Find payment by external_id or reference_id with known prefixes
     */
    protected function findPayment(array $data): ?Payment
    {
        $externalId = $data['external_id'] ?? null;
        $referenceId = $data['reference_id'] ?? null;

        // Try our custom prefixes first
        $prefixes = ['PAYMENT-', 'VA-', 'EWALLET-', 'QRIS-'];

        foreach ($prefixes as $prefix) {
            if ($externalId && str_starts_with($externalId, $prefix)) {
                $paymentId = str_replace($prefix, '', $externalId);
                return Payment::find($paymentId);
            }
            if ($referenceId && str_starts_with($referenceId, $prefix)) {
                $paymentId = str_replace($prefix, '', $referenceId);
                return Payment::find($paymentId);
            }
        }

        // Fallback: try to find by payment_gateway_id (Xendit invoice/charge ID)
        $xenditId = $data['id'] ?? null;
        if ($xenditId) {
            return Payment::where('payment_gateway_id', $xenditId)->first();
        }

        return null;
    }

    /**
     * Handle Invoice webhook
     */
    protected function handleInvoiceWebhook(array $data)
    {
        $payment = $this->findPayment($data);

        if (!$payment) {
            Log::warning('Payment not found for invoice webhook', [
                'external_id' => $data['external_id'] ?? null,
                'id' => $data['id'] ?? null,
            ]);
            // Return 200 to prevent Xendit from retrying (for test webhooks / unknown IDs)
            return response()->json(['success' => true, 'message' => 'Payment not found, skipped']);
        }

        // Prevent double processing
        if ($payment->isPaid()) {
            Log::info('Payment already paid, skipping', ['payment_id' => $payment->id]);
            return response()->json(['success' => true, 'message' => 'Already processed']);
        }

        $status = strtolower($data['status'] ?? '');

        Log::info('Processing invoice webhook', [
            'payment_id' => $payment->id,
            'status' => $status,
            'xendit_id' => $data['id'] ?? null,
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
                Log::info('Invoice status update (no action)', [
                    'payment_id' => $payment->id,
                    'status' => $status,
                ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Handle Virtual Account webhook
     * 
     * V1 format:
     * {
     *   "external_id": "VA-123" or "fixed-va-xxx",
     *   "callback_virtual_account_id": "...",
     *   "bank_code": "MANDIRI",
     *   "amount": 80000,
     *   ...
     * }
     */
    protected function handleVirtualAccountWebhook(array $data)
    {
        $payment = $this->findPayment($data);

        if (!$payment) {
            Log::warning('Payment not found for VA webhook', [
                'external_id' => $data['external_id'] ?? null,
                'id' => $data['id'] ?? null,
            ]);
            return response()->json(['success' => true, 'message' => 'Payment not found, skipped']);
        }

        if ($payment->isPaid()) {
            Log::info('Payment already paid, skipping', ['payment_id' => $payment->id]);
            return response()->json(['success' => true, 'message' => 'Already processed']);
        }

        // VA callback = payment received
        if (isset($data['callback_virtual_account_id']) || isset($data['amount'])) {
            Log::info('VA Payment received', [
                'payment_id' => $payment->id,
                'amount' => $data['amount'] ?? 0,
                'bank_code' => $data['bank_code'] ?? null,
            ]);

            $this->xenditService->handlePaymentSuccess($payment, [
                'id' => $data['id'] ?? null,
                'payment_channel' => 'VIRTUAL_ACCOUNT',
                'paid_amount' => $data['amount'] ?? $payment->total,
                'payment_id' => $data['payment_id'] ?? null,
                'bank_code' => $data['bank_code'] ?? null,
            ]);

            return response()->json(['success' => true]);
        }

        return response()->json(['success' => true, 'message' => 'VA event received']);
    }

    /**
     * Handle E-Wallet webhook
     * 
     * V2 format (inside data):
     * {
     *   "id": "ewc_...",
     *   "status": "SUCCEEDED",
     *   "reference_id": "EWALLET-123" or "test-payload",
     *   "channel_code": "ID_SHOPEEPAY",
     *   "charge_amount": 20000,
     *   ...
     * }
     */
    protected function handleEWalletWebhook(array $data)
    {
        $payment = $this->findPayment($data);

        if (!$payment) {
            Log::warning('Payment not found for E-Wallet webhook', [
                'reference_id' => $data['reference_id'] ?? null,
                'id' => $data['id'] ?? null,
            ]);
            return response()->json(['success' => true, 'message' => 'Payment not found, skipped']);
        }

        if ($payment->isPaid()) {
            Log::info('Payment already paid, skipping', ['payment_id' => $payment->id]);
            return response()->json(['success' => true, 'message' => 'Already processed']);
        }

        $status = strtoupper($data['status'] ?? '');

        Log::info('Processing E-Wallet webhook', [
            'payment_id' => $payment->id,
            'status' => $status,
            'charge_id' => $data['id'] ?? null,
            'channel' => $data['channel_code'] ?? null,
        ]);

        switch ($status) {
            case 'SUCCEEDED':
            case 'PAID':
            case 'CAPTURED':
                $this->xenditService->handlePaymentSuccess($payment, [
                    'id' => $data['id'] ?? null,
                    'payment_channel' => 'EWALLET',
                    'paid_amount' => $data['charge_amount'] ?? $data['capture_amount'] ?? $payment->total,
                    'ewallet_type' => $data['channel_code'] ?? 'unknown',
                ]);
                Log::info('E-Wallet payment successful', ['payment_id' => $payment->id]);
                break;

            case 'FAILED':
                $this->xenditService->handlePaymentFailed($payment, [
                    'failure_code' => $data['failure_code'] ?? 'unknown',
                ]);
                Log::info('E-Wallet payment failed', ['payment_id' => $payment->id]);
                break;

            case 'VOIDED':
                $payment->update([
                    'status' => 'expired',
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'voided_at' => $data['voided_at'] ?? now(),
                    ]),
                ]);
                Log::info('E-Wallet payment voided', ['payment_id' => $payment->id]);
                break;

            default:
                Log::info('E-Wallet status update (no action)', [
                    'payment_id' => $payment->id,
                    'status' => $status,
                ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Handle QRIS webhook
     * 
     * V2 format (inside data):
     * {
     *   "id": "qrpy_...",
     *   "status": "SUCCEEDED",
     *   "reference_id": "QRIS-123" or "testing_id_123",
     *   "amount": 1500,
     *   "channel_code": "ID_DANA",
     *   ...
     * }
     */
    protected function handleQRISWebhook(array $data)
    {
        $payment = $this->findPayment($data);

        if (!$payment) {
            Log::warning('Payment not found for QRIS webhook', [
                'reference_id' => $data['reference_id'] ?? null,
                'id' => $data['id'] ?? null,
            ]);
            return response()->json(['success' => true, 'message' => 'Payment not found, skipped']);
        }

        if ($payment->isPaid()) {
            Log::info('Payment already paid, skipping', ['payment_id' => $payment->id]);
            return response()->json(['success' => true, 'message' => 'Already processed']);
        }

        $status = strtoupper($data['status'] ?? '');

        Log::info('Processing QRIS webhook', [
            'payment_id' => $payment->id,
            'status' => $status,
            'qr_id' => $data['id'] ?? null,
            'channel' => $data['channel_code'] ?? null,
        ]);

        switch ($status) {
            case 'SUCCEEDED':
            case 'COMPLETED':
            case 'PAID':
                $this->xenditService->handlePaymentSuccess($payment, [
                    'id' => $data['id'] ?? null,
                    'payment_channel' => 'QRIS',
                    'paid_amount' => $data['amount'] ?? $payment->total,
                    'source' => $data['payment_detail']['source'] ?? ($data['channel_code'] ?? 'unknown'),
                ]);
                Log::info('QRIS payment successful', ['payment_id' => $payment->id]);
                break;

            case 'FAILED':
            case 'EXPIRED':
            case 'INACTIVE':
                $payment->update([
                    'status' => 'expired',
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'failure_reason' => $data['failure_code'] ?? $status,
                    ]),
                ]);
                Log::info('QRIS payment failed/expired', ['payment_id' => $payment->id]);
                break;

            default:
                Log::info('QRIS status update (no action)', [
                    'payment_id' => $payment->id,
                    'status' => $status,
                ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Handle Xendit V2 unified Payment Request webhook
     * 
     * Event: payment.succeeded, payment.failed, etc.
     */
    protected function handlePaymentRequestWebhook(array $data)
    {
        $payment = $this->findPayment($data);

        if (!$payment) {
            Log::warning('Payment not found for payment request webhook', [
                'reference_id' => $data['reference_id'] ?? null,
                'id' => $data['id'] ?? null,
            ]);
            return response()->json(['success' => true, 'message' => 'Payment not found, skipped']);
        }

        if ($payment->isPaid()) {
            return response()->json(['success' => true, 'message' => 'Already processed']);
        }

        $status = strtoupper($data['status'] ?? '');

        Log::info('Processing payment request webhook', [
            'payment_id' => $payment->id,
            'status' => $status,
        ]);

        switch ($status) {
            case 'SUCCEEDED':
            case 'PAID':
                $this->xenditService->handlePaymentSuccess($payment, [
                    'id' => $data['id'] ?? null,
                    'payment_channel' => $data['payment_method']['type'] ?? 'unknown',
                    'paid_amount' => $data['amount'] ?? $payment->total,
                ]);
                break;

            case 'FAILED':
                $this->xenditService->handlePaymentFailed($payment, [
                    'failure_code' => $data['failure_code'] ?? 'unknown',
                ]);
                break;

            case 'EXPIRED':
                $payment->update([
                    'status' => 'expired',
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'expired_at' => $data['updated'] ?? now(),
                    ]),
                ]);
                break;
        }

        return response()->json(['success' => true]);
    }

    /**
     * Handle unknown webhook type
     */
    protected function handleUnknownWebhook(string $event, array $data)
    {
        Log::warning('Unknown webhook type received', [
            'event' => $event,
            'id' => $data['id'] ?? $data['external_id'] ?? $data['reference_id'] ?? 'unknown',
        ]);

        // Return 200 so Xendit doesn't keep retrying
        return response()->json([
            'success' => true,
            'message' => "Webhook event '{$event}' received but not handled",
        ]);
    }
}