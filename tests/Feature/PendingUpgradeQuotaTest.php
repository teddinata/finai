<?php

use App\Models\Household;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\XenditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// Paket berbayar yang belum lunas tidak boleh mengubah kuota/paket yang sedang berjalan.

beforeEach(function () {
    Queue::fake();

    Plan::factory()->create(['slug' => 'premium-free', 'type' => 'free', 'price' => 0,
        'features' => ['max_ai_scans_per_month' => 5]]);
    $this->premium = Plan::factory()->create(['slug' => 'premium', 'type' => 'monthly', 'price' => 29000,
        'features' => ['max_ai_scans_per_month' => 50]]);
    $this->turbo = Plan::factory()->create(['slug' => 'turbo', 'type' => 'monthly', 'price' => 79000,
        'features' => ['max_ai_scans_per_month' => 500]]);

    $this->household = Household::factory()->create();
    $this->user = User::factory()->create(['household_id' => $this->household->id, 'role' => 'owner']);

    $this->active = Subscription::create([
        'household_id' => $this->household->id,
        'plan_id' => $this->premium->id,
        'billing_cycle' => 'monthly',
        'status' => 'active',
        'started_at' => now(),
        'expires_at' => now()->addMonth(),
    ]);
    $this->household->update(['current_subscription_id' => $this->active->id]);
});

function checkoutTurbo($test): Subscription
{
    $test->actingAs($test->user)
        ->postJson("/api/subscription/subscribe/{$test->turbo->id}", ['billing_cycle' => 'monthly'])
        ->assertCreated();

    return Subscription::where('plan_id', $test->turbo->id)->latest('id')->firstOrFail();
}

it('keeps the current plan and quota while the upgrade is unpaid', function () {
    $pending = checkoutTurbo($this);

    $household = $this->household->fresh();
    expect($pending->status)->toBe('pending')
        ->and($household->current_subscription_id)->toBe($this->active->id)
        ->and($this->active->fresh()->status)->toBe('active')
        ->and($household->getFeatureLimit('max_ai_scans_per_month'))->toBe(50);

    $this->actingAs($this->user)->getJson('/api/subscription')
        ->assertJsonPath('subscription.plan.slug', 'premium')
        ->assertJsonPath('pending_payment.subscription_id', $pending->id);
});

it('switches to the new plan only after payment succeeds', function () {
    $pending = checkoutTurbo($this);
    $payment = Payment::where('subscription_id', $pending->id)->firstOrFail();

    app(XenditService::class)->handlePaymentSuccess($payment, ['id' => 'pr-test', 'amount' => $payment->total]);

    $household = $this->household->fresh();
    expect($household->current_subscription_id)->toBe($pending->id)
        ->and($pending->fresh()->status)->toBe('active')
        ->and($this->active->fresh()->status)->toBe('canceled')
        ->and($household->getFeatureLimit('max_ai_scans_per_month'))->toBe(500);
});

it('drops the pending upgrade when its payment is canceled', function () {
    $pending = checkoutTurbo($this);
    $payment = Payment::where('subscription_id', $pending->id)->firstOrFail();

    $this->actingAs($this->user)->postJson("/api/payments/{$payment->id}/cancel")->assertOk();

    expect($pending->fresh()->status)->toBe('canceled')
        ->and($this->active->fresh()->status)->toBe('active')
        ->and($this->household->fresh()->current_subscription_id)->toBe($this->active->id);
});

it('uses free-plan quota for a household still pointing at a pending subscription', function () {
    // Data lama dari sebelum perbaikan: household sudah menunjuk ke subscription pending
    $legacy = Subscription::create([
        'household_id' => $this->household->id,
        'plan_id' => $this->turbo->id,
        'status' => 'pending',
        'started_at' => now(),
    ]);
    $this->household->update(['current_subscription_id' => $legacy->id]);

    expect($this->household->fresh()->getFeatureLimit('max_ai_scans_per_month'))->toBe(5);
});
