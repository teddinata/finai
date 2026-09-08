<?php

namespace App\Console\Commands;

use App\Services\Kirimdev\KirimdevClient;
use Illuminate\Console\Command;

/**
 * Uji arah keluar (Laravel -> Kirimdev -> WhatsApp) tanpa perlu ada
 * pesan masuk lebih dulu.
 *
 *   php artisan kirimdev:send 6285155095022 "halo dari laravel"
 */
class SendKirimdevMessage extends Command
{
    protected $signature = 'kirimdev:send
        {to? : Nomor tujuan (default KIRIMDEV_TEST_NUMBER)}
        {message=Halo dari Laravel. Ini tes kirim keluar lewat Kirimdev. : Isi pesan}
        {--phone-id= : Override KIRIMDEV_PHONE_NUMBER_ID}';

    protected $description = 'Kirim satu pesan WhatsApp lewat API Kirimdev';

    public function handle(KirimdevClient $kirimdev): int
    {
        $to = (string) ($this->argument('to') ?: config('services.kirimdev.test_number'));

        if ($to === '') {
            $this->error('Nomor tujuan kosong. Isi argumen `to` atau KIRIMDEV_TEST_NUMBER di .env.');

            return self::FAILURE;
        }

        $message = (string) $this->argument('message');

        $this->line('Kirim ke : ' . $kirimdev->normalizeRecipient($to));
        $this->line('Isi      : ' . $message);
        $this->line('');

        try {
            $response = $kirimdev->sendText($to, $message, $this->option('phone-id') ?: null);
        } catch (\Throwable $e) {
            $this->error('Gagal kirim: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Terkirim.');
        $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
