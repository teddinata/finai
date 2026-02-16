<?php

use App\Models\Household;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->household = Household::factory()->create(['name' => 'Test Household']);
    $this->user = User::factory()->create(['household_id' => $this->household->id, 'role' => 'owner']);
    $this->plan = Plan::factory()->create([
        'price' => 100000,
        'discount_price' => null,
        'slug' => 'premium-monthly'
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

test('it can validate a valid voucher', function () {
    $voucher = Voucher::create([
        'code' => 'TEST10',
        'name' => 'Test Voucher',
        'type' => 'percentage',
        'value' => 10,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/vouchers/validate', [
        'code' => 'TEST10',
        'plan_id' => $this->plan->id,
    ]);

    $response->assertStatus(200)
        ->assertJson([
        'valid' => true,
        'discount_amount' => 10000, // 10% of 100k
        'final_amount' => 90000,
    ]);
});

test('it can create payment with voucher', function () {
    $voucher = Voucher::create([
        'code' => 'FIXED20K',
        'name' => 'Test Voucher',
        'type' => 'fixed',
        'value' => 20000,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/payments', [
        'subscription_id' => $this->subscription->id,
        'payment_method' => 'virtual_account',
        'bank_code' => 'BNI',
        'voucher_code' => 'FIXED20K',
    ]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('payments', [
        'household_id' => $this->household->id,
        'voucher_id' => $voucher->id,
        'original_amount' => 100000,
        'discount_amount' => 20000,
        'amount' => 80000,
        'total' => 80000,
    ]);

    $this->assertDatabaseHas('voucher_usages', [
        'voucher_id' => $voucher->id,
        'household_id' => $this->household->id,
    ]);

    expect($voucher->fresh()->used_count)->toBe(1);
});

test('it handles 100 percent discount voucher', function () {
    $voucher = Voucher::create([
        'code' => 'FREE100',
        'name' => 'Test Voucher',
        'type' => 'percentage',
        'value' => 100,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/payments', [
        'subscription_id' => $this->subscription->id,
        'payment_method' => 'virtual_account',
        'bank_code' => 'BNI',
        'voucher_code' => 'FREE100',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.payment.amount', 0)
        ->assertJsonPath('data.payment.status', 'paid');

    expect($this->subscription->fresh()->status)->toBe('active');
});

test('it stacks plan discount and voucher', function () {
    $this->plan->update(['discount_price' => 80000]);

    $voucher = Voucher::create([
        'code' => 'EXTRA5K',
        'name' => 'Test Voucher',
        'type' => 'fixed',
        'value' => 5000,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/payments', [
        'subscription_id' => $this->subscription->id,
        'payment_method' => 'virtual_account',
        'bank_code' => 'BNI',
        'voucher_code' => 'EXTRA5K',
    ]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('payments', [
        'original_amount' => 80000,
        'discount_amount' => 5000,
        'amount' => 75000,
    ]);
});

test('it reverses voucher usage on cancellation', function () {
    $voucher = Voucher::create([
        'code' => 'CANCELME',
        'name' => 'Test Voucher',
        'type' => 'fixed',
        'value' => 10000,
        'max_uses' => 1,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/payments', [
        'subscription_id' => $this->subscription->id,
        'payment_method' => 'virtual_account',
        'bank_code' => 'BNI',
        'voucher_code' => 'CANCELME',
    ]);

    $paymentId = $response->json('data.payment.id');

    expect($voucher->fresh()->used_count)->toBe(1);

    $this->actingAs($this->user)
        ->postJson("/api/payments/{$paymentId}/cancel");

    expect($voucher->fresh()->used_count)->toBe(0);
    $this->assertDatabaseMissing('voucher_usages', [
        'voucher_id' => $voucher->id,
        'payment_id' => $paymentId,
    ]);
});