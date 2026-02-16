<?php

use App\Models\Household;
use App\Models\Plan;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->household = Household::factory()->create(['name' => 'Admin Household']);
    $this->admin = User::factory()->create([
        'household_id' => $this->household->id,
        'role' => 'admin',
    ]);
    $this->regularUser = User::factory()->create([
        'household_id' => $this->household->id,
        'role' => 'owner',
    ]);
});

// ── Authorization ──────────────────────────────────────────────

test('non-admin cannot access voucher management', function () {
    $response = $this->actingAs($this->regularUser)
        ->getJson('/api/admin/vouchers');

    $response->assertStatus(403);
});

test('unauthenticated user cannot access voucher management', function () {
    $response = $this->getJson('/api/admin/vouchers');

    $response->assertStatus(401);
});

// ── List (GET /api/admin/vouchers) ─────────────────────────────

test('admin can list vouchers', function () {
    Voucher::create([
        'code' => 'LIST1',
        'name' => 'First',
        'type' => 'fixed',
        'value' => 5000,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->admin->id,
    ]);
    Voucher::create([
        'code' => 'LIST2',
        'name' => 'Second',
        'type' => 'percentage',
        'value' => 10,
        'valid_from' => now()->subDay(),
        'is_active' => false,
        'created_by' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/admin/vouchers');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data');
});

test('admin can search vouchers by code', function () {
    Voucher::create([
        'code' => 'SEARCH_ME',
        'name' => 'Searchable',
        'type' => 'fixed',
        'value' => 5000,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->admin->id,
    ]);
    Voucher::create([
        'code' => 'OTHER',
        'name' => 'Another',
        'type' => 'fixed',
        'value' => 5000,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/admin/vouchers?search=SEARCH');

    $response->assertStatus(200);

    // Should find at least the matching voucher
    $data = $response->json('data');
    $codes = collect($data)->pluck('code')->toArray();
    expect($codes)->toContain('SEARCH_ME');
});

test('admin can filter vouchers by active status', function () {
    Voucher::create([
        'code' => 'ACTIVE1',
        'name' => 'Active',
        'type' => 'fixed',
        'value' => 5000,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->admin->id,
    ]);
    Voucher::create([
        'code' => 'INACTIVE1',
        'name' => 'Inactive',
        'type' => 'fixed',
        'value' => 5000,
        'valid_from' => now()->subDay(),
        'is_active' => false,
        'created_by' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/admin/vouchers?active=1');

    $response->assertStatus(200);
    $data = $response->json('data');
    foreach ($data as $v) {
        expect($v['is_active'])->toBeTrue();
    }
});

// ── Create (POST /api/admin/vouchers) ──────────────────────────

test('admin can create a voucher', function () {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/admin/vouchers', [
        'code' => 'NEW_VOUCHER',
        'name' => 'New Voucher',
        'type' => 'percentage',
        'value' => 15,
        'valid_from' => now()->toDateTimeString(),
        'is_active' => true,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('voucher.code', 'NEW_VOUCHER')
        ->assertJsonPath('voucher.type', 'percentage')
        ->assertJsonPath('voucher.value', 15);

    $this->assertDatabaseHas('vouchers', [
        'code' => 'NEW_VOUCHER',
        'created_by' => $this->admin->id,
    ]);
});

test('admin cannot create voucher with duplicate code', function () {
    Voucher::create([
        'code' => 'DUPLICATE',
        'name' => 'Original',
        'type' => 'fixed',
        'value' => 5000,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson('/api/admin/vouchers', [
        'code' => 'DUPLICATE',
        'name' => 'Copy',
        'type' => 'fixed',
        'value' => 10000,
        'valid_from' => now()->toDateTimeString(),
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

test('admin cannot create voucher without required fields', function () {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/admin/vouchers', [
        'code' => 'MISSING_FIELDS',
        // missing: name, type, value, valid_from
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'type', 'value', 'valid_from']);
});

test('admin cannot create voucher with invalid type', function () {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/admin/vouchers', [
        'code' => 'BADTYPE',
        'name' => 'Bad Type',
        'type' => 'invalid_type',
        'value' => 10,
        'valid_from' => now()->toDateTimeString(),
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

// ── Update (PUT /api/admin/vouchers/{id}) ──────────────────────

test('admin can update a voucher', function () {
    $voucher = Voucher::create([
        'code' => 'UPDATE_ME',
        'name' => 'Before Update',
        'type' => 'fixed',
        'value' => 5000,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->putJson("/api/admin/vouchers/{$voucher->id}", [
        'name' => 'After Update',
        'value' => 10000,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('voucher.name', 'After Update')
        ->assertJsonPath('voucher.value', 10000);

    $this->assertDatabaseHas('vouchers', [
        'id' => $voucher->id,
        'name' => 'After Update',
        'value' => 10000,
    ]);
});

test('admin can deactivate a voucher', function () {
    $voucher = Voucher::create([
        'code' => 'DEACTIVATE_ME',
        'name' => 'Active Voucher',
        'type' => 'fixed',
        'value' => 5000,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->putJson("/api/admin/vouchers/{$voucher->id}", [
        'is_active' => false,
    ]);

    $response->assertStatus(200);
    expect($voucher->fresh()->is_active)->toBeFalse();
});

// ── Delete (DELETE /api/admin/vouchers/{id}) ───────────────────

test('admin can delete unused voucher', function () {
    $voucher = Voucher::create([
        'code' => 'DELETE_ME',
        'name' => 'Delete Me',
        'type' => 'fixed',
        'value' => 5000,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->deleteJson("/api/admin/vouchers/{$voucher->id}");

    $response->assertStatus(200)
        ->assertJson(['message' => 'Voucher deleted successfully']);

    $this->assertDatabaseMissing('vouchers', ['id' => $voucher->id]);
});

test('admin cannot hard-delete voucher with usage history', function () {
    $voucher = Voucher::create([
        'code' => 'USED_VOUCHER',
        'name' => 'Used Voucher',
        'type' => 'fixed',
        'value' => 5000,
        'valid_from' => now()->subDay(),
        'is_active' => true,
        'created_by' => $this->admin->id,
    ]);

    // Create a usage record
    $plan = Plan::factory()->create(['price' => 100000, 'slug' => 'test-plan']);
    $sub = Subscription::create([
        'household_id' => $this->household->id,
        'plan_id' => $plan->id,
        'status' => 'pending',
        'billing_cycle' => 'monthly',
        'started_at' => now(),
        'expires_at' => now()->addMonth(),
    ]);
    $payment = Payment::create([
        'subscription_id' => $sub->id,
        'household_id' => $this->household->id,
        'user_id' => $this->admin->id,
        'amount' => 95000,
        'original_amount' => 100000,
        'discount_amount' => 5000,
        'voucher_id' => $voucher->id,
        'tax' => 0,
        'total' => 95000,
        'currency' => 'IDR',
        'payment_method' => 'virtual_account',
        'status' => 'pending',
    ]);
    VoucherUsage::create([
        'voucher_id' => $voucher->id,
        'household_id' => $this->household->id,
        'payment_id' => $payment->id,
        'discount_amount' => 5000,
    ]);

    $response = $this->actingAs($this->admin)
        ->deleteJson("/api/admin/vouchers/{$voucher->id}");

    $response->assertStatus(200)
        ->assertJson(['message' => 'Voucher deactivated (has usage history)']);

    // Voucher should still exist but deactivated
    $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'is_active' => false]);
});

test('admin gets 404 for non-existent voucher', function () {
    $response = $this->actingAs($this->admin)
        ->putJson('/api/admin/vouchers/99999', [
        'name' => 'Ghost',
    ]);

    $response->assertStatus(404);
});