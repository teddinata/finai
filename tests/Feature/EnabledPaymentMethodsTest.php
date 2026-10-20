<?php

use App\Models\Household;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// QRIS disembunyikan selama merchant QRIS di acquirer on hold (XENDIT_ENABLED_METHODS).

beforeEach(function () {
    $this->household = Household::factory()->create();
    $this->user = User::factory()->create(['household_id' => $this->household->id, 'role' => 'owner']);
});

it('lists only the enabled payment methods', function () {
    config(['xendit.enabled_payment_methods' => ['invoice', 'virtual_account', 'ewallet']]);

    $this->actingAs($this->user)->getJson('/api/payments/methods')
        ->assertOk()
        ->assertExactJson(['success' => true, 'methods' => ['invoice', 'virtual_account', 'ewallet']]);
});

it('rejects a disabled method when creating a payment', function () {
    config(['xendit.enabled_payment_methods' => ['invoice', 'virtual_account', 'ewallet']]);

    $plan = Plan::factory()->create(['slug' => 'turbo', 'price' => 79000]);
    $subscription = Subscription::create([
        'household_id' => $this->household->id,
        'plan_id' => $plan->id,
        'status' => 'pending',
        'started_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/payments', ['subscription_id' => $subscription->id, 'payment_method' => 'qris'])
        ->assertStatus(422);
});

it('hides QRIS by default until the merchant is reactivated', function () {
    expect(config('xendit.enabled_payment_methods'))->not->toContain('qris');
})->skip(fn () => env('XENDIT_ENABLED_METHODS') !== null, 'XENDIT_ENABLED_METHODS diatur di environment');
