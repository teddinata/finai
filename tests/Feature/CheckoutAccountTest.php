<?php

use App\Models\Plan;
use App\Models\User;
use App\Notifications\CheckoutAccountCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Plan::factory()->create(['slug' => 'premium-free', 'type' => 'free', 'price' => 0]);
});

test('checkout tanpa daftar membuat akun, token, dan mengirim password lewat email', function () {
    Notification::fake();

    $response = $this->postJson('/api/auth/checkout-account', [
        'name' => 'Budi',
        'email' => 'budi@example.com',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['token', 'user' => ['id', 'email', 'household']])
        ->assertJsonPath('password_sent_to', 'budi@example.com');

    $user = User::where('email', 'budi@example.com')->firstOrFail();
    expect($user->role)->toBe('owner');
    expect($user->household->name)->toBe('Keluarga Budi');
    expect($user->household->currentSubscription->plan->slug)->toBe('premium-free');

    Notification::assertSentTo($user, CheckoutAccountCreated::class, function ($notification) use ($user) {
        $lines = implode(' ', $notification->toMail($user)->introLines);
        preg_match('/Password: (\S+)/', $lines, $match);

        // Password di email harus benar-benar bisa dipakai login
        return isset($match[1]) && Hash::check($match[1], $user->password);
    });
});

test('email yang sudah terdaftar ditolak dan diarahkan untuk masuk', function () {
    Notification::fake();
    User::factory()->create(['email' => 'budi@example.com']);

    $this->postJson('/api/auth/checkout-account', [
        'name' => 'Budi',
        'email' => 'budi@example.com',
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'Email ini sudah terdaftar. Silakan masuk untuk melanjutkan pembayaran.');

    Notification::assertNothingSent();
});

test('akun dibatalkan kalau email password gagal terkirim', function () {
    Notification::shouldReceive('send')->andThrow(new RuntimeException('SMTP down'));

    $this->postJson('/api/auth/checkout-account', [
        'name' => 'Budi',
        'email' => 'budi@example.com',
    ])->assertStatus(500);

    expect(User::where('email', 'budi@example.com')->exists())->toBeFalse();
});
