<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Plan;
use App\Models\Household;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminManualUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $user;
    protected $household;
    protected $plan;
    protected $subscription;
    protected $payment;

    protected function setUp(): void
    {
        parent::setUp();

        // Create Admin
        $this->admin = User::factory()->create(['role' => 'admin']);

        // Create User & Household
        $this->user = User::factory()->create();
        $this->household = Household::factory()->create(['created_by' => $this->user->id]);
        $this->user->update(['household_id' => $this->household->id]);

        // Create Plan
        $this->plan = Plan::create([
            'name' => 'Premium Plan',
            'slug' => 'premium',
            'price' => 50000,
            'type' => 'monthly',
            'features' => [],
            'is_active' => true,
            'sort_order' => 1,
            'currency' => 'IDR'
        ]);

        // Create Subscription
        $this->subscription = Subscription::create([
            'household_id' => $this->household->id,
            'plan_id' => $this->plan->id,
            'status' => 'pending',
            'started_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        // Create Payment
        $this->payment = Payment::create([
            'user_id' => $this->user->id,
            'household_id' => $this->household->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 50000,
            'total' => 50000,
            'status' => 'pending',
            'payment_method' => 'manual',
            'currency' => 'IDR'
        ]);
    }

    public function test_admin_can_update_payment_status_to_paid()
    {
        // Act
        $response = $this->actingAs($this->admin)
            ->putJson("/api/admin/payments/{$this->payment->id}", [
            'status' => 'paid',
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonPath('payment.status', 'paid');

        $this->assertDatabaseHas('payments', [
            'id' => $this->payment->id,
            'status' => 'paid',
        ]);

        // Verify side effects (Subscription Activated)
        $this->assertDatabaseHas('subscriptions', [
            'id' => $this->subscription->id,
            'status' => 'active',
        ]);

        // Verify Invoice Created
        $this->assertDatabaseHas('invoices', [
            'payment_id' => $this->payment->id,
            'amount' => 50000,
        ]);
    }

    public function test_admin_can_update_payment_status_to_failed()
    {
        // Act
        $response = $this->actingAs($this->admin)
            ->putJson("/api/admin/payments/{$this->payment->id}", [
            'status' => 'failed',
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonPath('payment.status', 'failed');

        $this->assertDatabaseHas('payments', [
            'id' => $this->payment->id,
            'status' => 'failed',
        ]);

        // Verify Subscription Expired
        $this->assertDatabaseHas('subscriptions', [
            'id' => $this->subscription->id,
            'status' => 'expired',
        ]);
    }

    public function test_admin_can_update_subscription_manually()
    {
        // Act
        $response = $this->actingAs($this->admin)
            ->putJson("/api/admin/subscriptions/{$this->subscription->id}", [
            'status' => 'active',
            'expires_at' => now()->addYear()->toIso8601String(),
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonPath('subscription.status', 'active');

        $this->assertDatabaseHas('subscriptions', [
            'id' => $this->subscription->id,
            'status' => 'active',
        ]);
    }

    public function test_admin_payments_list_returns_full_data()
    {
        // Act
        $response = $this->actingAs($this->admin)
            ->getJson("/api/admin/payments");

        // Assert
        $response->assertStatus(200);

        // Assert structure contains nested objects
        $paymentData = $response->json('data')[0];

        $this->assertArrayHasKey('user', $paymentData);
        $this->assertArrayHasKey('email', $paymentData['user']);
        $this->assertArrayHasKey('created_at', $paymentData['user']);

        $this->assertArrayHasKey('household', $paymentData);
        $this->assertArrayHasKey('plan', $paymentData['subscription']);
    }
}