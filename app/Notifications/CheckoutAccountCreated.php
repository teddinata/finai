<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Dikirim saat akun dibuat otomatis lewat checkout tanpa daftar.
 * Sengaja tidak di-queue: password hanya ada di memori request ini.
 */
class CheckoutAccountCreated extends Notification
{
    use Queueable;

    public function __construct(private string $password)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $loginUrl = rtrim(config('app.frontend_url'), '/') . '/login';

        return (new MailMessage)
            ->subject('Akun Benah kamu sudah siap')
            ->greeting('Halo ' . $notifiable->name . '!')
            ->line('Akun Benah kamu dibuat otomatis saat checkout. Pakai data ini untuk masuk:')
            ->line('Email: ' . $notifiable->email)
            ->line('Password: ' . $this->password)
            ->action('Masuk ke Benah', $loginUrl)
            ->line('Demi keamanan, ganti password ini di menu Profil setelah berhasil masuk.')
            ->line('Kalau kamu tidak merasa melakukan checkout di Benah, abaikan email ini.')
            ->salutation('Salam, Tim Benah');
    }
}
