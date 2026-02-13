<?php

namespace App\Services;

use Xendit\Xendit;
use App\Models\Payment;
use App\Models\Invoice;

class XenditService
{
    protected $initialized = false;

    public function __construct()
    {
        // Lazy load initialization
    }

    protected function initXendit()
    {
        if ($this->initialized) {
            return;
        }

        if (!class_exists('\Xendit\Xendit')) {
            throw new \Exception('Xendit SDK not installed. Run: composer require xendit/xendit-php');
        }

        \Xendit\Xendit::setApiKey(config('services.xendit.secret_key'));
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

        $params = [
            'external_id' => 'PAYMENT-' . $payment->id,
            'amount' => $payment->total,
            'description' => "Subscription to {$plan->name} Plan",
            'invoice_duration' => 86400, // 24 hours
            'customer' => [
                'given_names' => $customerData['name'],
                'email' => $customerData['email'],
            ],
            'customer_notification_preference' => [
                'invoice_created' => ['email'],
                'invoice_reminder' => ['email'],
                'invoice_paid' => ['email'],
            ],
            'success_redirect_url' => config('services.xendit.success_redirect_url'),
            'failure_redirect_url' => config('services.xendit.failure_redirect_url'),
            'currency' => 'IDR',
            'items' => [
                [
                    'name' => $plan->name . ' Plan',
                    'quantity' => 1,
                    'price' => $payment->amount,
                    'category' => 'Subscription',
                ],
            ],
        ];

        if ($payment->tax > 0) {
            $params['fees'] = [
                [
                    'type' => 'Tax',
                    'value' => $payment->tax,
                ],
            ];
        }

        $xenditInvoice = \Xendit\Invoice::create($params);

        $payment->update([
            'payment_gateway_id' => $xenditInvoice['id'],
            'snap_token' => $xenditInvoice['invoice_url'],
            'metadata' => [
                'xendit_invoice_id' => $xenditInvoice['id'],
                'invoice_url' => $xenditInvoice['invoice_url'],
                'expiry_date' => $xenditInvoice['expiry_date'],
                'payment_method' => 'invoice',
            ],
        ]);

        return [
            'invoice_id' => $xenditInvoice['id'],
            'invoice_url' => $xenditInvoice['invoice_url'],
            'expiry_date' => $xenditInvoice['expiry_date'],
        ];
    }

    /**
     * Create Virtual Account
     */
    public function createVirtualAccount(Payment $payment, string $bankCode = 'BNI')
    {
        $this->initXendit();
        
        $validBanks = ['BNI', 'BRI', 'MANDIRI', 'PERMATA', 'BCA'];
        if (!in_array($bankCode, $validBanks)) {
            throw new \Exception("Invalid bank code. Must be one of: " . implode(', ', $validBanks));
        }

        $params = [
            'external_id' => 'VA-' . $payment->id,
            'bank_code' => $bankCode,
            'name' => $payment->user->name,
            'expected_amount' => $payment->total,
            'is_closed' => true, // Closed VA (exact amount)
            'expiration_date' => now()->addHours(24)->toIso8601String(),
        ];

        $va = \Xendit\VirtualAccounts::create($params);

        $payment->update([
            'payment_gateway_id' => $va['id'],
            'metadata' => array_merge($payment->metadata ?? [], [
                'va_number' => $va['account_number'],
                'bank_code' => $va['bank_code'],
                'va_id' => $va['id'],
                'payment_method' => 'virtual_account',
                'expected_amount' => $va['expected_amount'],
            ]),
        ]);

        return [
            'va_id' => $va['id'],
            'va_number' => $va['account_number'],
            'bank_code' => $va['bank_code'],
            'expected_amount' => $va['expected_amount'],
            'expiration_date' => $va['expiration_date'],
        ];
    }

    /**
     * Create E-Wallet Charge
     */
    public function createEWalletCharge(Payment $payment, string $ewalletType = 'OVO')
    {
        $this->initXendit();
        
        $validEwallets = ['OVO', 'DANA', 'LINKAJA', 'SHOPEEPAY'];
        if (!in_array($ewalletType, $validEwallets)) {
            throw new \Exception("Invalid e-wallet type. Must be one of: " . implode(', ', $validEwallets));
        }

        $params = [
            'reference_id' => 'EWALLET-' . $payment->id,
            'currency' => 'IDR',
            'amount' => $payment->total,
            'checkout_method' => 'ONE_TIME_PAYMENT',
            'channel_code' => $ewalletType,
            'channel_properties' => [
                'success_redirect_url' => config('services.xendit.success_redirect_url'),
                'failure_redirect_url' => config('services.xendit.failure_redirect_url'),
            ],
        ];

        $charge = \Xendit\EWallets::createEWalletCharge($params);

        $checkoutUrl = $charge['actions']['desktop_web_checkout_url'] ?? $charge['actions']['mobile_web_checkout_url'] ?? null;

        $payment->update([
            'payment_gateway_id' => $charge['id'],
            'snap_token' => $checkoutUrl,
            'metadata' => array_merge($payment->metadata ?? [], [
                'ewallet_type' => $ewalletType,
                'checkout_url' => $checkoutUrl,
                'mobile_url' => $charge['actions']['mobile_web_checkout_url'] ?? null,
                'desktop_url' => $charge['actions']['desktop_web_checkout_url'] ?? null,
                'payment_method' => 'ewallet',
            ]),
        ]);

        return [
            'charge_id' => $charge['id'],
            'checkout_url' => $checkoutUrl,
            'mobile_url' => $charge['actions']['mobile_web_checkout_url'] ?? null,
            'desktop_url' => $charge['actions']['desktop_web_checkout_url'] ?? null,
        ];
    }

    /**
     * Create QRIS Payment
     */
    public function createQRIS(Payment $payment)
    {
        $this->initXendit();
        
        $params = [
            'reference_id' => 'QRIS-' . $payment->id,
            'type' => 'DYNAMIC',
            'currency' => 'IDR',
            'amount' => $payment->total,
            'callback_url' => config('services.xendit.webhook_url'),
        ];

        $qris = \Xendit\QRCode::create($params);

        $payment->update([
            'payment_gateway_id' => $qris['id'],
            'snap_token' => $qris['qr_string'], // QR code string for display
            'metadata' => array_merge($payment->metadata ?? [], [
                'qris_id' => $qris['id'],
                'qr_string' => $qris['qr_string'],
                'payment_method' => 'qris',
            ]),
        ]);

        return [
            'qris_id' => $qris['id'],
            'qr_string' => $qris['qr_string'],
        ];
    }

    /**
     * Get Invoice/Payment Status
     */
    public function getInvoiceStatus(string $invoiceId)
    {
        $this->initXendit();
        return \Xendit\Invoice::retrieve($invoiceId);
    }

    /**
     * Get Virtual Account by ID
     */
    public function getVirtualAccount(string $vaId)
    {
        $this->initXendit();
        return \Xendit\VirtualAccounts::retrieve($vaId);
    }

    /**
     * Verify Webhook Token
     */
    public function verifyWebhookToken(string $token): bool
    {
        $expectedToken = config('services.xendit.webhook_token');
        
        if (empty($expectedToken)) {
            \Log::warning('Xendit webhook token not configured');
            return false;
        }

        return hash_equals($expectedToken, $token);
    }

    /**
     * Handle Payment Success
     */
    public function handlePaymentSuccess(Payment $payment, array $xenditData)
    {
        // Prevent double processing
        if ($payment->status === 'paid') {
            \Log::info('Payment already processed as paid', ['payment_id' => $payment->id]);
            return true;
        }

        $payment->markAsPaid($xenditData['id'], [
            'paid_via' => $xenditData['payment_channel'] ?? 'unknown',
            'paid_amount' => $xenditData['paid_amount'] ?? $payment->total,
            'xendit_fee' => $xenditData['xendit_fee'] ?? 0,
            'payment_id' => $xenditData['payment_id'] ?? null,
        ]);

        // Activate subscription
        if ($payment->subscription) {
            $subscription = $payment->subscription;
            
            // Calculate expiry based on plan type
            $expiresAt = match($subscription->plan->type) {
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

            \Log::info('Subscription activated', [
                'subscription_id' => $subscription->id,
                'plan' => $subscription->plan->name,
                'expires_at' => $expiresAt,
            ]);
        }

        // Create invoice
        $this->createOrUpdateInvoice($payment);

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
        ]);

        if ($payment->subscription) {
            $payment->subscription->update(['status' => 'expired']);
        }

        \Log::info('Payment marked as failed', [
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

            \Log::info('Invoice created', ['invoice_id' => $invoice->id]);
        } else {
            $invoice->update([
                'status' => 'paid',
                'paid_at' => $payment->paid_at,
            ]);

            \Log::info('Invoice updated', ['invoice_id' => $invoice->id]);
        }

        return $invoice;
    }
}