<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Simulasi kiriman webhook Kirimdev, supaya endpoint bisa diuji tanpa
 * harus kirim WhatsApp beneran dulu.
 *
 * Contoh:
 *   php artisan kirimdev:test-webhook
 *   php artisan kirimdev:test-webhook --text="catat makan siang 35rb pakai BCA"
 *   php artisan kirimdev:test-webhook --type=image
 *   php artisan kirimdev:test-webhook --url=https://api.benah.id/api/webhooks/kirimdev-test
 */
class TestKirimdevWebhook extends Command
{
    protected $signature = 'kirimdev:test-webhook
        {--url= : URL endpoint webhook (default APP_URL + /api/webhooks/kirimdev-test)}
        {--type=text : Jenis pesan: text atau image}
        {--text=halo test kirimdev : Isi pesan (atau caption untuk image)}
        {--from= : Nomor pengirim, format internasional tanpa + (contoh 628123456789)}
        {--phone-id= : phone_number_id nomor WhatsApp bisnis}
        {--event=message.received : Nama event di header X-Kirim-Event}
        {--media-url= : media_url untuk simulasi pesan gambar}
        {--secret= : Override KIRIMDEV_WEBHOOK_SECRET saat menandatangani}
        {--no-signature : Kirim tanpa header X-Kirim-Signature}
        {--invalid-signature : Kirim signature ngawur, untuk menguji penolakan}';

    protected $description = 'Kirim payload webhook Kirimdev palsu ke endpoint testing (tanpa perlu WhatsApp)';

    public function handle(): int
    {
        $url = $this->option('url')
            ?: rtrim((string) config('app.url'), '/') . '/api/webhooks/kirimdev-test';

        $payload = $this->buildPayload();
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Kirim-Event' => (string) $this->option('event'),
            'X-Kirim-Event-Id' => (string) Str::uuid(),
            'X-Kirim-Source' => 'artisan-simulator',
        ];

        if (! $this->option('no-signature')) {
            $headers['X-Kirim-Signature'] = $this->buildSignature($body);
        }

        $this->line('');
        $this->info('POST ' . $url);
        $this->line('Event   : ' . $headers['X-Kirim-Event']);
        $this->line('Signature: ' . ($headers['X-Kirim-Signature'] ?? '(tidak dikirim)'));
        $this->line('');
        $this->line($body);
        $this->line('');

        try {
            $response = Http::withHeaders($headers)
                ->withBody($body, 'application/json')
                ->timeout(30)
                ->post($url);
        } catch (\Throwable $e) {
            $this->error('Gagal menghubungi endpoint: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line('HTTP ' . $response->status());
        $this->line($response->body());
        $this->line('');

        if ($response->successful()) {
            $this->info('OK. Cek juga storage/logs/kirimdev.log');

            return self::SUCCESS;
        }

        $this->error('Endpoint membalas status non-2xx.');

        return self::FAILURE;
    }

    /**
     * Payload mengikuti struktur Meta yang dipakai Kirimdev untuk inbound
     * message, ditambah objek "kirim" untuk media.
     */
    private function buildPayload(): array
    {
        $from = (string) ($this->option('from') ?: config('services.kirimdev.test_number') ?: '628123456789');
        $phoneNumberId = (string) ($this->option('phone-id') ?: config('services.kirimdev.phone_number_id') ?: '000000000000000');
        $text = (string) $this->option('text');
        $isImage = $this->option('type') === 'image';

        $message = [
            'from' => $from,
            'id' => 'wamid.TEST' . strtoupper(Str::random(20)),
            'timestamp' => (string) time(),
            'type' => $isImage ? 'image' : 'text',
        ];

        if ($isImage) {
            $message['image'] = [
                'id' => (string) random_int(100000000000000, 999999999999999),
                'mime_type' => 'image/jpeg',
                'sha256' => hash('sha256', 'dummy-receipt'),
                'caption' => $text,
            ];
        } else {
            $message['text'] = ['body' => $text];
        }

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => (string) random_int(100000000000000, 999999999999999),
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '6287713751162',
                            'phone_number_id' => $phoneNumberId,
                        ],
                        'contacts' => [[
                            'profile' => ['name' => 'Tester'],
                            'wa_id' => $from,
                        ]],
                        'messages' => [$message],
                    ],
                ]],
            ]],
        ];

        if ($isImage) {
            $payload['kirim'] = [
                'event' => (string) $this->option('event'),
                'media_url' => (string) ($this->option('media-url') ?: 'https://media.kirimdev.com/dummy/receipt.jpg'),
                'media_status' => 'ready',
            ];
        }

        return $payload;
    }

    /**
     * Signature Kirimdev: HMAC-SHA256 atas "<timestamp>.<raw_body>".
     */
    private function buildSignature(string $body): string
    {
        $timestamp = time();

        if ($this->option('invalid-signature')) {
            return 't=' . $timestamp . ',v1=' . str_repeat('0', 64);
        }

        $secret = (string) ($this->option('secret') ?: config('services.kirimdev.webhook_secret'));

        if ($secret === '') {
            $this->warn('KIRIMDEV_WEBHOOK_SECRET kosong, signature dibuat dengan secret dummy.');
            $secret = 'dummy-secret';
        }

        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        return 't=' . $timestamp . ',v1=' . $signature;
    }
}
