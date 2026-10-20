<?php

use App\Models\Household;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\XenditService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Dulu Xendit mengembalikan pembeli ke {APP_URL}/payment/success (tanpa /api) dan mendarat di 404.

beforeEach(function () {
    config(['app.frontend_url' => 'https://pakaibenah.com']);

    $household = Household::factory()->create();
    $user = User::factory()->create(['household_id' => $household->id, 'role' => 'owner']);
    $plan = Plan::factory()->create(['slug' => 'turbo', 'price' => 79000]);
    $subscription = Subscription::create([
        'household_id' => $household->id,
        'plan_id' => $plan->id,
        'status' => 'pending',
        'started_at' => now(),
    ]);

    $this->payment = Payment::create([
        'subscription_id' => $subscription->id,
        'household_id' => $household->id,
        'user_id' => $user->id,
        'amount' => 79000,
        'total' => 79000,
        'status' => 'pending',
        'payment_method' => 'ewallet',
    ]);
    $this->payment->forceFill(['payment_token' => 'EWALLET-' . $this->payment->id])->save();
});

it('sends buyers straight back to the frontend page of their payment', function () {
    $urls = (fn () => $this->redirectUrls(...func_get_args()))->call(app(XenditService::class), $this->payment);

    expect($urls['success'])->toBe("https://pakaibenah.com/payment/success/{$this->payment->id}")
        ->and($urls['failure'])->toBe("https://pakaibenah.com/payment/waiting/{$this->payment->id}");
});

it('redirects the legacy return url without /api instead of returning 404', function () {
    $this->get('/payment/success')->assertRedirect('https://pakaibenah.com/payment/success');
    $this->get('/payment/failed')->assertRedirect('https://pakaibenah.com/payment/success');
});

it('keeps the payment id when the legacy return url carries a reference', function () {
    $this->get('/payment/success?reference_id=EWALLET-' . $this->payment->id)
        ->assertRedirect("https://pakaibenah.com/payment/success/{$this->payment->id}");

    $this->get('/api/payment/success?reference_id=EWALLET-' . $this->payment->id)
        ->assertRedirect("https://pakaibenah.com/payment/success/{$this->payment->id}");
});
