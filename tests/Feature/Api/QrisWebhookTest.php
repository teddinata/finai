<?php

namespace Tests\Feature\Api;

use App\Models\Payment;
use App\Models\User;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Household;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class QrisWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'test-token';

    private function makeQrisPayment(array $overrides = []): Payment
    {
        $user = User::factory()->create();
        $household = Household::factory()->create(['created_by' => $user->id]);
        $plan = Plan::create([
            'name' => 'Test Plan',
            'slug' => 'test-plan',
            'type' => 'monthly',
            'price' => 11120,
            'currency' => 'IDR',
            'features' => [],
            'description' => 'Test',
            'is_active' => true,
            'is_popular' => false,
            'sort_order' => 1,
        ]);
        $subscription = Subscription::create([
            'household_id' => $household->id,
            'plan_id' => $plan->id,
            'status' => 'pending',
            'started_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        $payment = Payment::create(array_merge([
            'user_id' => $user->id,
            'household_id' => $household->id,
            'subscription_id' => $subscription->id,
            'amount' => 11120,
            'total' => 11120,
            'status' => 'pending',
            'payment_method' => 'qris',
            'payment_gateway_id' => 'pr-1cd10b8b-7ae0-4ba7-9a3f-4e6c2f0c9a11',
            'metadata' => [
                'payment_method_id' => 'pm-6b173939-21d9-4495-8dab-4dc6f4c7d7db',
                'payment_method' => 'qris',
            ],
        ], $overrides));

        $payment->update(['payment_token' => 'QRIS-' . $payment->id]);

        Config::set('xendit.webhook_token', $this->token);

        return $payment->fresh();
    }

    private function sendWebhook(array $payload)
    {
        return $this->withHeader('x-callback-token', $this->token)
            ->postJson('/api/webhooks/xendit', $payload);
    }

    /**
     * payment_method.activated is the QR-created event, not a payment.
     * It must not settle or fail the payment, and it should record pm-xxx.
     */
    public function test_payment_method_activated_does_not_change_payment_status()
    {
        $payment = $this->makeQrisPayment(['metadata' => ['payment_method' => 'qris']]);

        $response = $this->sendWebhook([
            'id' => 'pm-6b173939-21d9-4495-8dab-4dc6f4c7d7db',
            'event' => 'payment_method.activated',
            'business_id' => '6665cd2adfa0ef2680d0251c',
            'created' => '2026-08-16T17:30:50.879143838Z',
            'data' => [
                'id' => 'pm-6b173939-21d9-4495-8dab-4dc6f4c7d7db',
                'type' => 'QR_CODE',
                'status' => 'ACTIVE',
                'reusability' => 'ONE_TIME_USE',
                // Xendit's own reference, NOT our QRIS-{id}
                'reference_id' => '4d0cb6a1-99ec-45dc-804f-388d1212063a',
                'qr_code' => [
                    'amount' => 11120,
                    'currency' => 'IDR',
                    'channel_code' => 'QRIS',
                    'channel_properties' => ['qr_string' => '00020101021226570011ID.DANA.WWW'],
                ],
            ],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
        ]);
    }

    /**
     * The QR Code channel webhook carries Xendit's own reference_id, so the
     * payment can only be resolved through the stored pm-xxx.
     */
    public function test_qr_payment_resolves_via_payment_method_id_metadata()
    {
        $payment = $this->makeQrisPayment();

        $response = $this->sendWebhook([
            'event' => 'qr.payment',
            'business_id' => '6665cd2adfa0ef2680d0251c',
            'created' => '2026-08-16T17:35:00Z',
            'data' => [
                'id' => 'qrpy_bd0e15b3-c9d2-4a09-b3e2-6d0f2a1c7c88',
                'qr_id' => 'pm-6b173939-21d9-4495-8dab-4dc6f4c7d7db',
                'amount' => 11120,
                'currency' => 'IDR',
                'status' => 'SUCCEEDED',
                'channel_code' => 'ID_DANA',
                'reference_id' => '4d0cb6a1-99ec-45dc-804f-388d1212063a',
            ],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('subscriptions', [
            'id' => $payment->subscription_id,
            'status' => 'active',
        ]);
    }

    /**
     * payment.succeeded for QRIS must settle the payment and keep pr-xxx in
     * payment_gateway_id so the API sync fallback stays usable.
     */
    public function test_payment_succeeded_qris_keeps_payment_request_id()
    {
        $payment = $this->makeQrisPayment();

        $response = $this->sendWebhook([
            'event' => 'payment.succeeded',
            'business_id' => '6665cd2adfa0ef2680d0251c',
            'created' => '2026-08-16T17:35:00Z',
            'data' => [
                'id' => '9f3f1a2b-51a1-4a5f-b6c1-2f1e7a9d0c33',
                'amount' => 11120,
                'currency' => 'IDR',
                'status' => 'SUCCEEDED',
                'reference_id' => 'QRIS-' . $payment->id,
                'payment_request_id' => 'pr-1cd10b8b-7ae0-4ba7-9a3f-4e6c2f0c9a11',
                'payment_method' => [
                    'id' => 'pm-6b173939-21d9-4495-8dab-4dc6f4c7d7db',
                    'type' => 'QR_CODE',
                    'reference_id' => '4d0cb6a1-99ec-45dc-804f-388d1212063a',
                ],
            ],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
            'payment_gateway_id' => 'pr-1cd10b8b-7ae0-4ba7-9a3f-4e6c2f0c9a11',
        ]);
    }

    /**
     * Xendit issues a different callback token per webhook endpoint. Any token
     * not on the accepted list gets a 401, which makes Xendit retry forever.
     */
    public function test_secondary_callback_token_is_accepted()
    {
        $payment = $this->makeQrisPayment();

        Config::set('xendit.webhook_token', 'IxUOYalZ-primary-token');
        Config::set('xendit.webhook_tokens', ['MaMf3Go8-secondary-token']);

        $payload = [
            'event' => 'payment.succeeded',
            'business_id' => '6665cd2adfa0ef2680d0251c',
            'created' => '2026-08-16T17:35:00Z',
            'data' => [
                'id' => '9f3f1a2b-51a1-4a5f-b6c1-2f1e7a9d0c33',
                'amount' => 11120,
                'status' => 'SUCCEEDED',
                'reference_id' => 'QRIS-' . $payment->id,
                'payment_method' => ['id' => 'pm-x', 'type' => 'QR_CODE'],
            ],
        ];

        $this->withHeader('x-callback-token', 'MaMf3Go8-secondary-token')
            ->postJson('/api/webhooks/xendit', $payload)
            ->assertStatus(200);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);
    }

    public function test_unknown_callback_token_is_rejected()
    {
        $this->makeQrisPayment();

        Config::set('xendit.webhook_token', 'IxUOYalZ-primary-token');
        Config::set('xendit.webhook_tokens', ['MaMf3Go8-secondary-token']);

        $this->withHeader('x-callback-token', 'not-my-token')
            ->postJson('/api/webhooks/xendit', ['event' => 'payment.succeeded', 'data' => []])
            ->assertStatus(401);
    }

    /**
     * Payments API v3 uses payment_id / request_amount instead of id / amount.
     */
    public function test_payment_capture_v3_field_names_are_supported()
    {
        $payment = $this->makeQrisPayment();

        $response = $this->sendWebhook([
            'event' => 'payment.capture',
            'business_id' => '6665cd2adfa0ef2680d0251c',
            'created' => '2026-08-16T17:35:00Z',
            'data' => [
                'payment_id' => 'ps-9f3f1a2b-51a1-4a5f-b6c1-2f1e7a9d0c33',
                'payment_request_id' => 'pr-1cd10b8b-7ae0-4ba7-9a3f-4e6c2f0c9a11',
                'reference_id' => 'QRIS-' . $payment->id,
                'request_amount' => 11120,
                'currency' => 'IDR',
                'status' => 'SUCCEEDED',
                'channel_code' => 'QRIS',
            ],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);
    }
}
