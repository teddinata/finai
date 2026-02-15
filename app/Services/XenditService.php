<?php

namespace App\Services;

use Xendit\Configuration;
use Xendit\Invoice\InvoiceApi;
use Xendit\PaymentRequest\PaymentRequestApi;
use App\Models\Payment;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

class XenditService
{
    protected $initialized = false;
    protected $invoiceApi;

    public function __construct()
    {
        // Lazy load initialization
    }

    protected function initXendit()
    {
        if ($this->initialized) {
            return;
        }

        Configuration::setXenditKey(config('xendit.secret_key'));
        $this->invoiceApi = new InvoiceApi();
        $this->initialized = true;
    }

    /**
     * Create Invoice (Payment Link)
     */
    public function createInvoice(Payment $payment, array $customerData)
    {
        $this->initXendit();

        $household = $payment->household;
        $plan = $payment->subscription->plan;

        $externalId = 'PAYMENT-' . $payment->id;

        // ✅ Langsung pakai amount (sudah IDR)
        $params = [
            'external_id' => $externalId,
            'amount' => $payment->total, // ✅ Langsung total
            'description' => "Subscription to {$plan->name} Plan",
            'invoice_duration' => 86400,
            'customer' => [
                'given_names' => $customerData['name'],
                'email' => $customerData['email'],
            ],
            'customer_notification_preference' => [
                'invoice_created' => ['email'],
                'invoice_reminder' => ['email'],
                'invoice_paid' => ['email'],
            ],
            'success_redirect_url' => config('xendit.success_redirect_url'),
            'failure_redirect_url' => config('xendit.failure_redirect_url'),
            'currency' => 'IDR',
            'items' => [
                [
                    'name' => $plan->name . ' Plan',
                    'quantity' => 1,
                    'price' => $payment->amount, // ✅ Langsung amount
                    'category' => 'Subscription',
                ],
            ],
        ];

        if ($payment->tax > 0) {
            $params['fees'] = [
                [
                    'type' => 'Tax',
                    'value' => $payment->tax, // ✅ Langsung tax
                ],
            ];
        }

        $xenditInvoice = $this->invoiceApi->createInvoice($params);

        $payment->update([
            'payment_gateway_id' => $xenditInvoice['id'],
            'payment_token' => $externalId,
            'snap_token' => $xenditInvoice['invoice_url'],
            'metadata' => [
                'xendit_invoice_id' => $xenditInvoice['id'],
                'invoice_url' => $xenditInvoice['invoice_url'],
                'expiry_date' => $xenditInvoice['expiry_date'],
                'payment_method' => 'invoice',
                'external_id' => $externalId,
            ],
        ]);

        return [
            'invoice_id' => $xenditInvoice['id'],
            'invoice_url' => $xenditInvoice['invoice_url'],
            'expiry_date' => $xenditInvoice['expiry_date'],
        ];
    }

    /**
     * Create Virtual Account using Payment Request API
     */
    public function createVirtualAccount(Payment $payment, string $bankCode = 'BNI')
    {
        $this->initXendit();

        $apiInstance = new PaymentRequestApi();

        $validBanks = ['BNI', 'BRI', 'MANDIRI', 'PERMATA', 'BCA'];
        if (!in_array($bankCode, $validBanks)) {
            throw new \Exception("Invalid bank code. Must be one of: " . implode(', ', $validBanks));
        }

        $referenceId = 'VA-' . $payment->id;

        // Get user from payment
        $user = $payment->user;
        if (!$user) {
            throw new \Exception("Payment has no associated user");
        }

        $virtualAccountParams = new \Xendit\PaymentRequest\VirtualAccountParameters([
            'channel_code' => $bankCode,
            'channel_properties' => new \Xendit\PaymentRequest\VirtualAccountChannelProperties([
                'customer_name' => $user->name,
                'expires_at' => now()->addHours(24)->toIso8601String(),
            ]),
        ]);

        $paymentMethodParams = new \Xendit\PaymentRequest\PaymentMethodParameters([
            'type' => 'VIRTUAL_ACCOUNT',
            'reusability' => 'ONE_TIME_USE',
            'virtual_account' => $virtualAccountParams,
        ]);

        $paymentRequestParams = new \Xendit\PaymentRequest\PaymentRequestParameters([
            'reference_id' => $referenceId,
            'amount' => $payment->total, // ✅ Langsung total (IDR)
            'currency' => \Xendit\PaymentRequest\PaymentRequestCurrency::IDR,
            'payment_method' => $paymentMethodParams
        ]);

        try {
            $result = $apiInstance->createPaymentRequest(null, null, null, $paymentRequestParams);
        } catch (\Xendit\XenditSdkException $e) {
            Log::error('Xendit Create VA Error: ' . $e->getMessage());
            throw $e;
        }

        $vaNumber = $result['payment_method']['virtual_account']['channel_properties']['virtual_account_number'] ?? null;
        $vaId = $result['id'];
        $expiryDate = $result['payment_method']['virtual_account']['channel_properties']['expires_at'] ?? null;

        $payment->update([
            'payment_gateway_id' => $vaId,
            'payment_token' => $referenceId,
            'metadata' => array_merge($payment->metadata ?? [], [
                'va_number' => $vaNumber,
                'bank_code' => $bankCode,
                'va_id' => $vaId,
                'payment_method' => 'virtual_account',
                'expected_amount' => $payment->total,
                'reference_id' => $referenceId,
            ]),
        ]);

        return [
            'va_id' => $vaId,
            'va_number' => $vaNumber,
            'bank_code' => $bankCode,
            'expected_amount' => $payment->total,
            'expiration_date' => $expiryDate,
        ];
    }

    /**
     * Create E-Wallet Charge using Payment Request API
     */
    public function createEWalletCharge(Payment $payment, string $ewalletType = 'OVO')
    {
        $this->initXendit();

        $apiInstance = new PaymentRequestApi();

        $validEwallets = ['OVO', 'DANA', 'LINKAJA', 'SHOPEEPAY'];
        if (!in_array($ewalletType, $validEwallets)) {
            throw new \Exception("Invalid e-wallet type. Must be one of: " . implode(', ', $validEwallets));
        }

        $referenceId = 'EWALLET-' . $payment->id;

        $ewalletParams = new \Xendit\PaymentRequest\EWalletParameters([
            'channel_code' => $ewalletType,
            'channel_properties' => new \Xendit\PaymentRequest\EWalletChannelProperties([
                'success_return_url' => config('xendit.success_redirect_url'),
                'failure_return_url' => config('xendit.failure_redirect_url'),
            ]),
        ]);

        $paymentMethodParams = new \Xendit\PaymentRequest\PaymentMethodParameters([
            'type' => 'EWALLET',
            'reusability' => 'ONE_TIME_USE',
            'ewallet' => $ewalletParams,
        ]);

        $paymentRequestParams = new \Xendit\PaymentRequest\PaymentRequestParameters([
            'reference_id' => $referenceId,
            'amount' => $payment->total, // ✅ Langsung total (IDR)
            'currency' => \Xendit\PaymentRequest\PaymentRequestCurrency::IDR,
            'payment_method' => $paymentMethodParams
        ]);

        try {
            $result = $apiInstance->createPaymentRequest(null, null, null, $paymentRequestParams);
        } catch (\Xendit\XenditSdkException $e) {
            Log::error('Xendit Create EWallet Error: ' . $e->getMessage());
            throw $e;
        }

        $actions = $result['actions'] ?? [];
        $checkoutUrl = null;

        foreach ($actions as $action) {
            if ($action['action'] === 'AUTH') {
                $checkoutUrl = $action['url'];
                break;
            }
        }

        $payment->update([
            'payment_gateway_id' => $result['id'],
            'payment_token' => $referenceId,
            'snap_token' => $checkoutUrl,
            'metadata' => array_merge($payment->metadata ?? [], [
                'ewallet_type' => $ewalletType,
                'checkout_url' => $checkoutUrl,
                'payment_method' => 'ewallet',
                'reference_id' => $referenceId,
            ]),
        ]);

        return [
            'charge_id' => $result['id'],
            'checkout_url' => $checkoutUrl,
        ];
    }

    /**
     * Create QRIS Payment using Payment Request API
     */
    public function createQRIS(Payment $payment)
    {
        $this->initXendit();

        $apiInstance = new PaymentRequestApi();

        $referenceId = 'QRIS-' . $payment->id;

        $qrParams = new \Xendit\PaymentRequest\QRCodeParameters([
            'channel_code' => 'QRIS',
        ]);

        $paymentMethodParams = new \Xendit\PaymentRequest\PaymentMethodParameters([
            'type' => 'QR_CODE',
            'reusability' => 'ONE_TIME_USE',
            'qr_code' => $qrParams,
        ]);

        $paymentRequestParams = new \Xendit\PaymentRequest\PaymentRequestParameters([
            'reference_id' => $referenceId,
            'amount' => $payment->total, // ✅ Langsung total (IDR)
            'currency' => \Xendit\PaymentRequest\PaymentRequestCurrency::IDR,
            'payment_method' => $paymentMethodParams
        ]);

        try {
            $result = $apiInstance->createPaymentRequest(null, null, null, $paymentRequestParams);
        } catch (\Xendit\XenditSdkException $e) {
            Log::error('Xendit Create QRIS Error: ' . $e->getMessage());
            throw $e;
        }

        $qrString = $result['payment_method']['qr_code']['channel_properties']['qr_string'] ?? null;

        $payment->update([
            'payment_gateway_id' => $result['id'],
            'payment_token' => $referenceId,
            'snap_token' => $qrString,
            'metadata' => array_merge($payment->metadata ?? [], [
                'qris_id' => $result['id'],
                'qr_string' => $qrString,
                'payment_method' => 'qris',
                'reference_id' => $referenceId,
            ]),
        ]);

        return [
            'qris_id' => $result['id'],
            'qr_string' => $qrString,
        ];
    }

    /**
     * Get Payment Request by ID
     */
    public function getPaymentRequest(string $prId)
    {
        $this->initXendit();
        $apiInstance = new PaymentRequestApi();
        return $apiInstance->getPaymentRequestByID($prId);
    }

    /**
     * Verify Webhook Token
     */
    public function verifyWebhookToken(string $token): bool
    {
        $expectedToken = config('xendit.webhook_token');

        if (empty($expectedToken)) {
            Log::warning('Xendit webhook token not configured');
            return false;
        }

        return hash_equals($expectedToken, $token);
    }

    /**
     * Handle Payment Success
     */
    public function handlePaymentSuccess(Payment $payment, $xenditData)
    {
        Log::info('=== handlePaymentSuccess START ===', [
            'payment_id' => $payment->id,
            'current_status' => $payment->status,
            'xendit_data' => $xenditData,
        ]);

        // Convert object to array if needed
        if (is_object($xenditData) && method_exists($xenditData, 'toArray')) {
            $xenditData = $xenditData->toArray();
        } elseif (is_object($xenditData)) {
            $xenditData = (array)$xenditData;
        }

        // Prevent double processing
        if ($payment->isPaid()) {
            Log::info('Payment already processed as paid', ['payment_id' => $payment->id]);
            return true;
        }

        // Data Mapping
        $paidVia = $xenditData['payment_channel'] ?? $xenditData['payment_method']['type'] ?? 'unknown';
        $paidAmount = $xenditData['paid_amount'] ?? $xenditData['amount'] ?? $payment->total;

        Log::info('Marking payment as paid', [
            'payment_id' => $payment->id,
            'paid_via' => $paidVia,
            'paid_amount' => $paidAmount,
        ]);

        $payment->markAsPaid($xenditData['id'], [
            'paid_via' => $paidVia,
            'paid_amount' => $paidAmount,
            'xendit_fee' => $xenditData['xendit_fee'] ?? 0,
            'payment_id' => $xenditData['payment_id'] ?? null,
            'webhook_received_at' => now()->toIso8601String(),
        ]);

        Log::info('Payment marked as paid', [
            'payment_id' => $payment->id,
            'new_status' => $payment->fresh()->status,
        ]);

        // Activate subscription
        if ($payment->subscription) {
            $subscription = $payment->subscription;

            Log::info('Activating subscription', [
                'subscription_id' => $subscription->id,
                'plan' => $subscription->plan->name,
            ]);

            // Calculate expiry based on plan type
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

            // Set as current subscription
            $payment->household->update([
                'current_subscription_id' => $subscription->id,
            ]);

            Log::info('Subscription activated', [
                'subscription_id' => $subscription->id,
                'plan' => $subscription->plan->name,
                'expires_at' => $expiresAt,
            ]);
        }

        // Create invoice
        $this->createOrUpdateInvoice($payment);

        Log::info('=== handlePaymentSuccess END ===', [
            'payment_id' => $payment->id,
            'final_status' => $payment->fresh()->status,
        ]);

        return true;
    }

    /**
     * Handle Payment Failed
     */
    public function handlePaymentFailed(Payment $payment, array $xenditData)
    {
        $payment->markAsFailed([
            'failure_code' => $xenditData['failure_code'] ?? 'unknown',
            'failure_message' => $xenditData['failure_message'] ?? null,
            'webhook_received_at' => now()->toIso8601String(),
        ]);

        if ($payment->subscription) {
            $payment->subscription->update(['status' => 'expired']);
        }

        Log::info('Payment marked as failed', [
            'payment_id' => $payment->id,
            'failure_code' => $xenditData['failure_code'] ?? 'unknown',
        ]);

        return true;
    }

    /**
     * Create or Update Invoice
     */
    private function createOrUpdateInvoice(Payment $payment)
    {
        $invoice = $payment->invoice;

        if (!$invoice) {
            $invoice = Invoice::create([
                'household_id' => $payment->household_id,
                'payment_id' => $payment->id,
                'subscription_id' => $payment->subscription_id,
                'invoice_number' => Invoice::generateInvoiceNumber(),
                'amount' => $payment->amount,
                'tax' => $payment->tax,
                'total' => $payment->total,
                'currency' => $payment->currency,
                'status' => 'paid',
                'description' => 'Subscription Payment - ' . ($payment->subscription->plan->name ?? 'Unknown Plan'),
                'line_items' => [
                    [
                        'description' => $payment->subscription->plan->name . ' Plan',
                        'quantity' => 1,
                        'unit_price' => $payment->amount,
                        'amount' => $payment->amount,
                    ],
                ],
                'issued_at' => now(),
                'paid_at' => $payment->paid_at,
            ]);

            Log::info('Invoice created', ['invoice_id' => $invoice->id]);
        } else {
            $invoice->update([
                'status' => 'paid',
                'paid_at' => $payment->paid_at,
            ]);

            Log::info('Invoice updated', ['invoice_id' => $invoice->id]);
        }

        return $invoice;
    }
}