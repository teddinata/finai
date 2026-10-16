<?php

use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

const GENERIC_MESSAGE = 'Kalau email itu terdaftar, link untuk membuat password baru sudah dikirim.';

test('lupa password mengirim link ke halaman frontend', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'sari@example.com']);

    $this->postJson('/api/auth/forgot-password', ['email' => 'sari@example.com'])
        ->assertOk()
        ->assertJsonPath('message', GENERIC_MESSAGE);

    Notification::assertSentTo($user, ResetPasswordLink::class, function ($notification) use ($user) {
        return str_starts_with(
            $notification->resetUrl($user),
            rtrim(config('app.frontend_url'), '/') . '/reset-password?token='
        );
    });
});

test('email yang tidak terdaftar mendapat jawaban yang sama', function () {
    Notification::fake();

    $this->postJson('/api/auth/forgot-password', ['email' => 'tidakada@example.com'])
        ->assertOk()
        ->assertJsonPath('message', GENERIC_MESSAGE);

    Notification::assertNothingSent();
});

test('akun nonaktif tidak dikirimi link', function () {
    Notification::fake();
    User::factory()->create(['email' => 'off@example.com', 'active' => false]);

    $this->postJson('/api/auth/forgot-password', ['email' => 'off@example.com'])->assertOk();

    Notification::assertNothingSent();
});

test('gagal kirim email tetap dijawab sama dan tidak error 500', function () {
    User::factory()->create(['email' => 'sari@example.com']);
    Notification::shouldReceive('send')->andThrow(new RuntimeException('SMTP down'));

    $this->postJson('/api/auth/forgot-password', ['email' => 'sari@example.com'])
        ->assertOk()
        ->assertJsonPath('message', GENERIC_MESSAGE);
});

test('reset password mengganti password, mengeluarkan sesi lama, dan memverifikasi email', function () {
    $user = User::factory()->unverified()->create(['email' => 'sari@example.com']);
    $user->createToken('lama');
    $token = Password::createToken($user);

    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => 'sari@example.com',
        'password' => 'passwordbaru1',
        'password_confirmation' => 'passwordbaru1',
    ])->assertOk();

    $user->refresh();
    expect(Hash::check('passwordbaru1', $user->password))->toBeTrue();
    expect($user->tokens()->count())->toBe(0);
    expect($user->hasVerifiedEmail())->toBeTrue();

    $this->postJson('/api/auth/login', ['email' => 'sari@example.com', 'password' => 'passwordbaru1'])->assertOk();
});

test('token hanya bisa dipakai sekali', function () {
    $user = User::factory()->create(['email' => 'sari@example.com']);
    $token = Password::createToken($user);
    $payload = [
        'token' => $token,
        'email' => 'sari@example.com',
        'password' => 'passwordbaru1',
        'password_confirmation' => 'passwordbaru1',
    ];

    $this->postJson('/api/auth/reset-password', $payload)->assertOk();
    $this->postJson('/api/auth/reset-password', $payload)->assertStatus(422);
});

test('token salah ditolak dan password tidak berubah', function () {
    $user = User::factory()->create(['email' => 'sari@example.com', 'password' => 'passwordlama1']);

    $this->postJson('/api/auth/reset-password', [
        'token' => 'token-ngawur',
        'email' => 'sari@example.com',
        'password' => 'passwordbaru1',
        'password_confirmation' => 'passwordbaru1',
    ])->assertStatus(422);

    expect(Hash::check('passwordlama1', $user->fresh()->password))->toBeTrue();
});

test('token untuk satu akun tidak bisa dipakai di akun lain', function () {
    $sari = User::factory()->create(['email' => 'sari@example.com']);
    User::factory()->create(['email' => 'budi@example.com', 'password' => 'passwordbudi1']);
    $token = Password::createToken($sari);

    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => 'budi@example.com',
        'password' => 'passwordbaru1',
        'password_confirmation' => 'passwordbaru1',
    ])->assertStatus(422);

    expect(Hash::check('passwordbudi1', User::where('email', 'budi@example.com')->first()->password))->toBeTrue();
});
