<?php

namespace Tests\Feature\Api;

use App\Models\Payment;
use App\Models\User;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Household;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_payment_succeeded_updates_payment_status()
    {
        // 1. Setup
        $user = User::factory()->create();
        $household = Household::factory()->create(['created_by' => $user->id]);
        $plan = Plan::create([
            'name' => 'Test Plan',
            'slug' => 'test-plan',
            'type' => 'monthly',
            'price' => 10000,
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

        // Create Payment
        $payment = Payment::create([
            'user_id' => $user->id,
            'household_id' => $household->id,
            'subscription_id' => $subscription->id,
            'amount' => 10000,
            'total' => 10000,
            'status' => 'pending',
            'payment_method' => 'virtual_account',
            'payment_gateway_id' => 'pr-b54cd058-3647-42f9-b839-274a276243a7',
        ]);

        // Mock Config for Token
        $token = 'test-token';
        Config::set('xendit.webhook_token', $token);

        // 2. Payload from User
        $payload = [
            "created" => "2026-02-14T07:15:52.767Z",
            "business_id" => "6665cd2adfa0ef2680d0251c",
            "event" => "payment.succeeded",
            "data" => [
                "id" => "6f425d45-3934-4d1d-bfc6-41588eb7c857",
                "items" => null,
                "amount" => 10000,
                "status" => "SUCCEEDED",
                "country" => "ID",
                "created" => "2026-02-14T07:15:52Z",
                "updated" => "2026-02-14T07:15:52Z",
                "currency" => "IDR",
                "metadata" => null,
                "customer_id" => null,
                "description" => null,
                "failure_code" => null,
                "reference_id" => "VA-" . $payment->id, // Dynamic Match
                "payment_detail" => [],
                "payment_method" => [
                    "id" => "pm-94ee9c74-a274-47e1-8f42-282a18d2a6f2",
                    "card" => null,
                    "type" => "VIRTUAL_ACCOUNT",
                    "status" => "EXPIRED",
                    "created" => "2026-02-14T07:15:09.339421Z",
                    "ewallet" => null,
                    "qr_code" => null,
                    "updated" => "2026-02-14T07:15:52.662962Z",
                    "metadata" => null,
                    "description" => null,
                    "reusability" => "ONE_TIME_USE",
                    "direct_debit" => null,
                    "reference_id" => "96e592fb-2821-4ca5-833c-3a1cdc4fb274",
                    "virtual_account" => [
                        "amount" => 10000,
                        "currency" => "IDR",
                        "channel_code" => "BRI",
                        "channel_properties" => [
                            "expires_at" => "2026-02-15T07:15:09Z",
                            "customer_name" => "GASCPNS Indonesia",
                            "virtual_account_number" => "13282927803867487"
                        ]
                    ],
                    "over_the_counter" => null,
                    "billing_information" => [
                        "city" => null,
                        "country" => "",
                        "postal_code" => null,
                        "street_line1" => null,
                        "street_line2" => null,
                        "province_state" => null
                    ],
                    "direct_bank_transfer" => null
                ],
                "channel_properties" => null,
                "payment_request_id" => "pr-b54cd058-3647-42f9-b839-274a276243a7"
            ],
            "api_version" => null
        ];

        // 3. Act
        $response = $this->withHeader('x-callback-token', $token)
            ->postJson('/api/webhooks/xendit', $payload);

        // 4. Assert
        $response->dump();
        $response->assertStatus(200);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => 'active',
        ]);
    }
}