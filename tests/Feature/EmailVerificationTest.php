<?php

use App\Models\Plan;
use App\Models\User;
use App\Notifications\CustomVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

function verificationLink(User $user, ?Carbon $expires = null): string
{
    return URL::temporarySignedRoute(
        'verification.verify',
        $expires ?? now()->addHours(CustomVerifyEmail::EXPIRE_HOURS),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );
}

function frontendTarget(string $status): string
{
    return rtrim(config('app.frontend_url'), '/') . '/email-verified?status=' . $status;
}

test('register tetap berhasil walau email verifikasi gagal terkirim', function () {
    Plan::factory()->create(['slug' => 'premium-free', 'type' => 'free', 'price' => 0]);
    Notification::shouldReceive('send')->andThrow(new RuntimeException('SMTP down'));

    $this->postJson('/api/auth/register', [
        'name' => 'Sari',
        'email' => 'sari@example.com',
        'password' => 'rahasia123',
        'password_confirmation' => 'rahasia123',
        'household_name' => 'Keluarga Sari',
    ])
        ->assertCreated()
        ->assertJsonPath('email_verification_sent', false);

    expect(User::where('email', 'sari@example.com')->exists())->toBeTrue();
});

test('link verifikasi yang valid menandai email terverifikasi', function () {
    $user = User::factory()->unverified()->create();

    $this->get(verificationLink($user))->assertRedirect(frontendTarget('success'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('link kedaluwarsa diarahkan ke frontend, bukan error JSON', function () {
    $user = User::factory()->unverified()->create();

    $this->get(verificationLink($user, now()->subMinute()))->assertRedirect(frontendTarget('expired'));

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('link untuk email lama tidak berlaku setelah email diganti', function () {
    $user = User::factory()->unverified()->create(['email' => 'lama@example.com']);
    $link = verificationLink($user);
    $user->update(['email' => 'baru@example.com']);

    $this->get($link)->assertRedirect(frontendTarget('invalid'));

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('email yang sudah terverifikasi diarahkan dengan status already-verified', function () {
    $user = User::factory()->create();

    $this->get(verificationLink($user))->assertRedirect(frontendTarget('already-verified'));
});

test('kirim link verifikasi dari aplikasi', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->postJson('/api/email/send')->assertOk();

    Notification::assertSentTo($user, CustomVerifyEmail::class);
});

test('kirim link mengembalikan 503 saat email gagal terkirim', function () {
    $user = User::factory()->unverified()->create();
    Notification::shouldReceive('send')->andThrow(new RuntimeException('SMTP down'));

    $this->actingAs($user)->postJson('/api/email/send')->assertStatus(503);
});

test('mengganti email mereset verifikasi dan mengirim link baru', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'lama@example.com']);

    $this->actingAs($user)
        ->putJson('/api/auth/profile', ['email' => 'baru@example.com'])
        ->assertOk()
        ->assertJsonPath('user.email_verified_at', null)
        ->assertJsonPath('email_verification_sent', true);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, CustomVerifyEmail::class);
});

test('mengganti nama saja tidak mereset verifikasi', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/auth/profile', ['name' => 'Nama Baru'])->assertOk();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Notification::assertNothingSent();
});
