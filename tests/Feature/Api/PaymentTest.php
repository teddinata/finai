<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Plan;
use App\Models\Household;
use App\Models\Subscription;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;
use App\Services\XenditService;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $household;
    protected $plan;
    protected $subscription;
    protected $xenditMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->user = User::factory()->create([
            'household_id' => $this->household->id,
            'role' => 'owner',
        ]);

        $this->plan = Plan::create([
            'name' => 'Pertalite',
            'slug' => 'pertalite',
            'price' => 10000,
            'type' => 'monthly',
            'features' => [],
        ]);

        $this->subscription = Subscription::create([
            'household_id' => $this->household->id,
            'plan_id' => $this->plan->id,
            'status' => 'pending',
            'started_at' => now(),
        ]);

        // Mock XenditService to avoid real API calls
        $this->xenditMock = Mockery::mock(XenditService::class);
        $this->app->instance(XenditService::class , $this->xenditMock);
    }

    public function test_existing_pending_payment_blocks_new_payment()
    {
        // 1. Create existing pending payment
        Payment::create([
            'subscription_id' => $this->subscription->id,
            'household_id' => $this->household->id,
            'user_id' => $this->user->id,
            'amount' => 10000,
            'total' => 10000,
            'status' => 'pending',
            'payment_method' => 'xendit',
        ]);

        // Expectation for Xendit create call
        $this->xenditMock->shouldReceive('createVirtualAccount')
            ->once()
            ->andReturn([
            'va_number' => '1234567890',
            'id' => 'va_id_123',
            'bank_code' => 'BCA',
            'expected_amount' => 10000,
            'expiration_date' => now()->addDay()->toIso8601String(),
        ]);

        // 2. Try to create new payment
        $response = $this->actingAs($this->user)
            ->postJson('/api/payments', [
            'subscription_id' => $this->subscription->id,
            'payment_method' => 'virtual_account',
            'bank_code' => 'BCA',
        ]);

        // 3. Expect 201 Created (New Behavior)
        $response->assertStatus(201)
            ->assertJson([
            'message' => 'Payment created successfully',
        ]);

        $this->assertEquals('expired', Payment::find(1)->status);
    }

}