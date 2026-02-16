<?php

use App\Models\Household;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Models\Payment;
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

// ── applicable_plans restriction ───────────────────────────────

test('it rejects voucher not applicable to this plan', function () {
    $otherPlan = Plan::factory()->create(['price' => 200000, 'slug' => 'enterprise-monthly']);

    Voucher::create([
        'code' => 'PLAN_SPECIFIC',
        'name' => 'Plan Specific',
        'type' => 'fixed',
        'value' => 10000,
        'applicable_plans' => [$otherPlan->id],
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/vouchers/validate', [
            'code' => 'PLAN_SPECIFIC',
            'plan_id' => $this->plan->id,
        ]);

    $response->assertStatus(422)
        ->assertJson(['valid' => false]);
});

test('it accepts voucher applicable to this plan', function () {
    Voucher::create([
        'code' => 'PLAN_MATCH',
        'name' => 'Plan Match',
        'type' => 'fixed',
        'value' => 10000,
        'applicable_plans' => [$this->plan->id],
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/vouchers/validate', [
            'code' => 'PLAN_MATCH',
            'plan_id' => $this->plan->id,
        ]);

    $response->assertStatus(200)
        ->assertJson(['valid' => true]);
});

test('it accepts voucher with empty applicable_plans (all plans)', function () {
    Voucher::create([
        'code' => 'ALL_PLANS',
        'name' => 'All Plans',
        'type' => 'fixed',
        'value' => 10000,
        'applicable_plans' => null,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/vouchers/validate', [
            'code' => 'ALL_PLANS',
            'plan_id' => $this->plan->id,
        ]);

    $response->assertStatus(200)
        ->assertJson(['valid' => true]);
});

// ── max_uses_per_household ─────────────────────────────────────

test('it rejects voucher when household usage limit reached', function () {
    $voucher = Voucher::create([
        'code' => 'HOUSEHOLD_LIMIT',
        'name' => 'Household Limit',
        'type' => 'fixed',
        'value' => 10000,
        'max_uses_per_household' => 1,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $payment = Payment::create([
        'subscription_id' => $this->subscription->id,
        'household_id' => $this->household->id,
        'user_id' => $this->user->id,
        'amount' => 90000,
        'original_amount' => 100000,
        'discount_amount' => 10000,
        'voucher_id' => $voucher->id,
        'tax' => 0,
        'total' => 90000,
        'currency' => 'IDR',
        'payment_method' => 'virtual_account',
        'status' => 'pending',
    ]);

    VoucherUsage::create([
        'voucher_id' => $voucher->id,
        'household_id' => $this->household->id,
        'payment_id' => $payment->id,
        'discount_amount' => 10000,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/vouchers/validate', [
            'code' => 'HOUSEHOLD_LIMIT',
            'plan_id' => $this->plan->id,
        ]);

    $response->assertStatus(422)
        ->assertJson(['valid' => false]);
});

test('it allows voucher when different household uses it', function () {
    $voucher = Voucher::create([
        'code' => 'HOUSEHOLD_OK',
        'name' => 'Household OK',
        'type' => 'fixed',
        'value' => 10000,
        'max_uses_per_household' => 1,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $otherHousehold = Household::factory()->create(['name' => 'Other Household']);
    $otherUser = User::factory()->create(['household_id' => $otherHousehold->id, 'role' => 'owner']);
    $otherSub = Subscription::create([
        'household_id' => $otherHousehold->id,
        'plan_id' => $this->plan->id,
        'status' => 'pending',
        'billing_cycle' => 'monthly',
        'started_at' => now(),
        'expires_at' => now()->addMonth(),
    ]);
    $otherPayment = Payment::create([
        'subscription_id' => $otherSub->id,
        'household_id' => $otherHousehold->id,
        'user_id' => $otherUser->id,
        'amount' => 90000,
        'original_amount' => 100000,
        'discount_amount' => 10000,
        'voucher_id' => $voucher->id,
        'tax' => 0,
        'total' => 90000,
        'currency' => 'IDR',
        'payment_method' => 'virtual_account',
        'status' => 'pending',
    ]);
    VoucherUsage::create([
        'voucher_id' => $voucher->id,
        'household_id' => $otherHousehold->id,
        'payment_id' => $otherPayment->id,
        'discount_amount' => 10000,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/vouchers/validate', [
            'code' => 'HOUSEHOLD_OK',
            'plan_id' => $this->plan->id,
        ]);

    $response->assertStatus(200)
        ->assertJson(['valid' => true]);
});

// ── max_discount_amount (percentage cap) ───────────────────────

test('percentage discount is capped by max_discount_amount', function () {
    Voucher::create([
        'code' => 'CAPPED50',
        'name' => 'Capped 50%',
        'type' => 'percentage',
        'value' => 50,
        'max_discount_amount' => 20000,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/vouchers/validate', [
            'code' => 'CAPPED50',
            'plan_id' => $this->plan->id,
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'valid' => true,
            'discount_amount' => 20000,
            'final_amount' => 80000,
        ]);
});

// ── Fixed discount larger than amount ──────────────────────────

test('fixed discount cannot exceed purchase amount', function () {
    Voucher::create([
        'code' => 'OVERSIZED',
        'name' => 'Oversized Discount',
        'type' => 'fixed',
        'value' => 200000,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/vouchers/validate', [
            'code' => 'OVERSIZED',
            'plan_id' => $this->plan->id,
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'valid' => true,
            'discount_amount' => 100000,
            'final_amount' => 0,
        ]);
});

// ── Multiple households, same voucher (global counter) ─────────

test('voucher used_count is shared globally across households', function () {
    $voucher = Voucher::create([
        'code' => 'GLOBAL2',
        'name' => 'Global Counter',
        'type' => 'fixed',
        'value' => 5000,
        'max_uses' => 2,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    // First household uses it
    $response1 = $this->actingAs($this->user)
        ->postJson('/api/payments', [
            'subscription_id' => $this->subscription->id,
            'payment_method' => 'virtual_account',
            'bank_code' => 'BNI',
            'voucher_code' => 'GLOBAL2',
        ]);
    $response1->assertStatus(201);
    expect($voucher->fresh()->used_count)->toBe(1);

    // Second household
    $household2 = Household::factory()->create(['name' => 'Household 2']);
    $user2 = User::factory()->create(['household_id' => $household2->id, 'role' => 'owner']);
    $sub2 = Subscription::create([
        'household_id' => $household2->id,
        'plan_id' => $this->plan->id,
        'status' => 'pending',
        'billing_cycle' => 'monthly',
        'started_at' => now(),
        'expires_at' => now()->addMonth(),
    ]);

    $response2 = $this->actingAs($user2)
        ->postJson('/api/payments', [
            'subscription_id' => $sub2->id,
            'payment_method' => 'virtual_account',
            'bank_code' => 'BNI',
            'voucher_code' => 'GLOBAL2',
        ]);
    $response2->assertStatus(201);
    expect($voucher->fresh()->used_count)->toBe(2);

    // Third attempt should fail (max_uses = 2)
    $household3 = Household::factory()->create(['name' => 'Household 3']);
    $user3 = User::factory()->create(['household_id' => $household3->id, 'role' => 'owner']);
    $sub3 = Subscription::create([
        'household_id' => $household3->id,
        'plan_id' => $this->plan->id,
        'status' => 'pending',
        'billing_cycle' => 'monthly',
        'started_at' => now(),
        'expires_at' => now()->addMonth(),
    ]);

    $response3 = $this->actingAs($user3)
        ->postJson('/api/vouchers/validate', [
            'code' => 'GLOBAL2',
            'plan_id' => $this->plan->id,
        ]);
    $response3->assertStatus(422)
        ->assertJson(['valid' => false]);
});