<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordLink extends Notification
{
    use Queueable;

    public function __construct(public string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = config('auth.passwords.users.expire');

        return (new MailMessage)
            ->subject('Atur ulang password akun Benah')
            ->greeting('Halo ' . $notifiable->name . '!')
            ->line('Kami menerima permintaan untuk mengatur ulang password akun Benah kamu.')
            ->action('Buat password baru', $this->resetUrl($notifiable))
            ->line('Link ini berlaku ' . $minutes . ' menit dan hanya bisa dipakai sekali.')
            ->line('Kalau kamu tidak meminta ini, abaikan email ini. Password kamu tidak berubah.')
            ->salutation('Salam, Tim Benah');
    }

    public function resetUrl(object $notifiable): string
    {
        return rtrim(config('app.frontend_url'), '/') . '/reset-password?' . http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }
}
