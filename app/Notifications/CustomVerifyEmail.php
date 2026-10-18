<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
// use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class CustomVerifyEmail extends Notification 
{
    use Queueable;

    // Verifikasi tidak diwajibkan di depan, jadi link dibuat cukup lama untuk dibuka belakangan
    public const EXPIRE_HOURS = 24;

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->subject('Verifikasi email akun Benah kamu')
            ->greeting('Halo ' . $notifiable->name . '!')
            ->line('Ketuk tombol di bawah untuk memastikan email ini milikmu. Email yang terverifikasi dipakai untuk memulihkan akun kalau kamu lupa password.')
            ->action('Verifikasi email', $verificationUrl)
            ->line('Link ini berlaku ' . self::EXPIRE_HOURS . ' jam. Kalau sudah lewat, kirim ulang dari menu Profil di aplikasi.')
            ->line('Kalau kamu tidak merasa mendaftar di Benah, abaikan email ini.')
            ->salutation('Salam, Tim Benah');
    }

    /**
     * Get the verification URL for the given notifiable.
     */
    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addHours(self::EXPIRE_HOURS),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }
}