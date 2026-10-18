<?php

use App\Jobs\SendMetaPurchaseEvent;
use App\Models\Household;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Meta\MetaConversionsApi;
use App\Services\XenditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.meta.pixel_id' => '1234567890',
        'services.meta.access_token' => 'test-token',
        'services.meta.test_event_code' => null,
        'services.meta.graph_version' => 'v23.0',
        'services.meta.queue' => false,
    ]);
});

function metaTestPayment(array $overrides = []): Payment
{
    $user = User::factory()->create(['name' => 'Budi Santoso', 'email' => 'Budi@Example.com']);
    $household = Household::factory()->create(['created_by' => $user->id]);
    $user->update(['household_id' => $household->id]);

    $plan = Plan::factory()->create(['name' => 'Turbo', 'slug' => 'turbo', 'type' => 'lifetime', 'price' => 179000]);

    $subscription = Subscription::create([
        'household_id' => $household->id,
        'plan_id' => $plan->id,
        'status' => 'pending',
        'started_at' => now(),
        'expires_at' => now()->addMonth(),
    ]);

    return Payment::create(array_merge([
        'user_id' => $user->id,
        'household_id' => $household->id,
        'subscription_id' => $subscription->id,
        'amount' => 43200,
        'total' => 43200,
        'currency' => 'IDR',
        'status' => 'paid',
        'paid_at' => now(),
        'payment_method' => 'qris',
        'metadata' => [
            'meta_tracking' => [
                'fbp' => 'fb.1.1700000000000.123456789',
                'fbc' => 'fb.1.1700000000000.AbCdEf',
                'client_ip_address' => '203.0.113.7',
                'client_user_agent' => 'Mozilla/5.0',
                'event_source_url' => 'https://pakaibenah.com/payment/method/1',
            ],
        ],
    ], $overrides));
}

test('job mengirim Purchase dengan event_id yang sama seperti Pixel dan data pembeli ter-hash', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'trace-1'])]);
    $payment = metaTestPayment();

    (new SendMetaPurchaseEvent($payment->id))->handle(app(MetaConversionsApi::class));

    Http::assertSent(function ($request) use ($payment) {
        $event = $request['data'][0];

        return $request->url() === 'https://graph.facebook.com/v23.0/1234567890/events'
            && $request['access_token'] === 'test-token'
            && !isset($request['test_event_code'])
            && $event['event_name'] === 'Purchase'
            && $event['event_id'] === 'purchase-' . $payment->id
            && $event['action_source'] === 'website'
            && $event['event_source_url'] === 'https://pakaibenah.com/payment/method/1'
            && $event['custom_data']['value'] === 43200
            && $event['custom_data']['currency'] === 'IDR'
            && $event['custom_data']['content_ids'] === [(string) $payment->subscription->plan_id]
            && $event['user_data']['em'] === [hash('sha256', 'budi@example.com')]
            && $event['user_data']['fn'] === [hash('sha256', 'budi')]
            && $event['user_data']['ln'] === [hash('sha256', 'santoso')]
            && $event['user_data']['external_id'] === [hash('sha256', (string) $payment->user_id)]
            && $event['user_data']['fbp'] === 'fb.1.1700000000000.123456789'
            && $event['user_data']['fbc'] === 'fb.1.1700000000000.AbCdEf'
            && $event['user_data']['client_ip_address'] === '203.0.113.7';
    });

    $result = $payment->fresh()->metadata['meta_capi'];
    expect($result['events_received'])->toBe(1);
    expect($result['purchase_sent_at'])->not->toBeNull();
    // Data pencocokan lama tetap ada
    expect($payment->fresh()->metadata['meta_tracking']['fbp'])->toBe('fb.1.1700000000000.123456789');
});

test('pembayaran yang sudah terkirim tidak dikirim ulang', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
    $payment = metaTestPayment();

    (new SendMetaPurchaseEvent($payment->id))->handle(app(MetaConversionsApi::class));
    (new SendMetaPurchaseEvent($payment->id))->handle(app(MetaConversionsApi::class));

    Http::assertSentCount(1);
});

test('test_event_code ikut terkirim saat diisi', function () {
    config(['services.meta.test_event_code' => 'TEST123']);
    Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
    $payment = metaTestPayment();

    (new SendMetaPurchaseEvent($payment->id))->handle(app(MetaConversionsApi::class));

    Http::assertSent(fn ($request) => $request['test_event_code'] === 'TEST123');
});

test('tidak mengirim apa pun kalau pixel atau token belum diisi', function () {
    config(['services.meta.access_token' => null]);
    Http::fake();
    $payment = metaTestPayment();

    (new SendMetaPurchaseEvent($payment->id))->handle(app(MetaConversionsApi::class));

    Http::assertNothingSent();
});

test('error dari Meta dicatat dan tidak menggagalkan proses', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 400)]);
    $payment = metaTestPayment();

    (new SendMetaPurchaseEvent($payment->id))->handle(app(MetaConversionsApi::class));

    $result = $payment->fresh()->metadata['meta_capi'];
    expect($result['last_error'])->toContain('Invalid token');
    expect($result)->not->toHaveKey('purchase_sent_at');
});

test('pelunasan lewat XenditService menjadwalkan event Purchase', function () {
    Bus::fake();
    $payment = metaTestPayment(['status' => 'pending', 'paid_at' => null]);

    app(XenditService::class)->handlePaymentSuccess($payment, [
        'id' => 'pr-test',
        'payment_channel' => 'QRIS',
        'paid_amount' => 43200,
    ]);

    Bus::assertDispatchedAfterResponse(
        SendMetaPurchaseEvent::class,
        fn ($job) => $job->paymentId === $payment->id
    );
});

test('pelunasan manual oleh admin tidak dikirim ke Meta', function () {
    Bus::fake();
    $payment = metaTestPayment(['status' => 'pending', 'paid_at' => null]);

    app(XenditService::class)->handlePaymentSuccess($payment, [
        'id' => 'manual-1',
        'payment_channel' => 'MANUAL_ADMIN',
        'paid_amount' => 43200,
    ]);

    Bus::assertNotDispatchedAfterResponse(SendMetaPurchaseEvent::class);
});
