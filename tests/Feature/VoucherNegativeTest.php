<?php

use App\Models\Household;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->household = Household::factory()->create(['name' => 'Test Household']);
    $this->user = User::factory()->create(['household_id' => $this->household->id, 'role' => 'owner']);
    $this->plan = Plan::factory()->create([
        'price' => 100000,
        'discount_price' => null,
        'slug' => 'premium-monthly',
    ]);
    $this->subscription = Subscription::create([
        'household_id' => $this->household->id,
        'plan_id' => $this->plan->id,
        'status' => 'pending',
        'billing_cycle' => 'monthly',
        'started_at' => now(),
        'expires_at' => now()->addMonth(),
    ]);
});

// ── Voucher not found ──────────────────────────────────────────

test('it rejects non-existent voucher code', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/vouchers/validate', [
        'code' => 'DOESNOTEXIST',
        'plan_id' => $this->plan->id,
    ]);

    $response->assertStatus(422)
        ->assertJson(['valid' => false]);
});

// ── Expired voucher ────────────────────────────────────────────

test('it rejects expired voucher', function () {
    Voucher::create([
        'code' => 'EXPIRED10',
        'name' => 'Expired Voucher',
        'type' => 'percentage',
        'value' => 10,
        'valid_from' => now()->subMonth(),
        'valid_until' => now()->subDay(), // Already expired
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/vouchers/validate', [
        'code' => 'EXPIRED10',
        'plan_id' => $this->plan->id,
    ]);

    $response->assertStatus(422)
        ->assertJson(['valid' => false]);
});

// ── Inactive voucher ───────────────────────────────────────────

test('it rejects inactive voucher', function () {
    Voucher::create([
        'code' => 'INACTIVE10',
        'name' => 'Inactive Voucher',
        'type' => 'percentage',
        'value' => 10,
        'valid_from' => now()->subDay(),
        'is_active' => false, // Deactivated
        'created_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/vouchers/validate', [
        'code' => 'INACTIVE10',
        'plan_id' => $this->plan->id,
    ]);

    $response->assertStatus(422)
        ->assertJson(['valid' => false]);
});

// ── Future voucher (not yet valid) ─────────────────────────────

test('it rejects voucher that has not started yet', function () {
    Voucher::create([
        'code' => 'FUTURE10',
        'name' => 'Future Voucher',
        'type' => 'percentage',
        'value' => 10,
        'valid_from' => now()->addDay(), // Not yet valid
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/vouchers/validate', [
        'code' => 'FUTURE10',
        'plan_id' => $this->plan->id,
    ]);

    $response->assertStatus(422)
        ->assertJson(['valid' => false]);
});

// ── Global max_uses reached ────────────────────────────────────

test('it rejects voucher when global max_uses reached', function () {
    $voucher = Voucher::create([
        'code' => 'MAXED',
        'name' => 'Maxed Voucher',
        'type' => 'fixed',
        'value' => 5000,
        'max_uses' => 1,
        'used_count' => 1, // Already used once
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/vouchers/validate', [
        'code' => 'MAXED',
        'plan_id' => $this->plan->id,
    ]);

    $response->assertStatus(422)
        ->assertJson(['valid' => false]);
});

// ── min_purchase_amount not met ────────────────────────────────

test('it rejects voucher when min purchase amount not met', function () {
    Voucher::create([
        'code' => 'MINPURCHASE',
        'name' => 'Min Purchase Voucher',
        'type' => 'fixed',
        'value' => 50000,
        'min_purchase_amount' => 200000, // Plan price is 100k
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/vouchers/validate', [
        'code' => 'MINPURCHASE',
        'plan_id' => $this->plan->id,
    ]);

    $response->assertStatus(422)
        ->assertJson(['valid' => false]);
});

// ── Invalid voucher during payment (not just validation) ───────

test('it rejects payment with expired voucher code', function () {
    Voucher::create([
        'code' => 'EXPIRED_PAY',
        'name' => 'Expired Payment Voucher',
        'type' => 'fixed',
        'value' => 10000,
        'valid_from' => now()->subMonth(),
        'valid_until' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/payments', [
        'subscription_id' => $this->subscription->id,
        'payment_method' => 'virtual_account',
        'bank_code' => 'BNI',
        'voucher_code' => 'EXPIRED_PAY',
    ]);

    // Should fail with validation error, not 201
    $response->assertStatus(422);

    // No payment should be created
    $this->assertDatabaseMissing('payments', [
        'subscription_id' => $this->subscription->id,
    ]);
});

// ── Payment without voucher still works ────────────────────────

test('it creates payment without voucher code', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/payments', [
        'subscription_id' => $this->subscription->id,
        'payment_method' => 'virtual_account',
        'bank_code' => 'BNI',
    ]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('payments', [
        'subscription_id' => $this->subscription->id,
        'amount' => 100000,
        'discount_amount' => 0,
        'voucher_id' => null,
    ]);
});