<?php

namespace App\Services\Kirimdev;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Client untuk arah Laravel -> Kirimdev -> WhatsApp.
 *
 * Endpoint Kirimdev drop-in compatible dengan Meta WhatsApp Cloud API:
 *   POST https://api.kirimdev.com/v1/{phone_number_id}/messages
 *   Authorization: Bearer kdv_live_...
 *
 * Kredensial yang dipakai di sini (API key) berbeda dengan webhook secret
 * yang dipakai untuk memverifikasi arah sebaliknya.
 */
class KirimdevClient
{
    public function sendText(string $to, string $message, ?string $phoneNumberId = null): array
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $this->normalizeRecipient($to),
            'type' => 'text',
            'text' => [
                'body' => $message,
                'preview_url' => false,
            ],
        ], $phoneNumberId);
    }

    /**
     * Balas sambil mengutip pesan aslinya, supaya di WhatsApp balasannya
     * nempel ke pesan yang dimaksud.
     */
    public function replyText(string $to, string $message, string $replyToMessageId, ?string $phoneNumberId = null): array
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $this->normalizeRecipient($to),
            'context' => ['message_id' => $replyToMessageId],
            'type' => 'text',
            'text' => [
                'body' => $message,
                'preview_url' => false,
            ],
        ], $phoneNumberId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function send(array $payload, ?string $phoneNumberId = null): array
    {
        $apiKey = trim((string) config('services.kirimdev.api_key'));

        if ($apiKey === '') {
            throw new RuntimeException('KIRIMDEV_API_KEY belum diisi di .env');
        }

        $phoneNumberId = $phoneNumberId ?: (string) config('services.kirimdev.phone_number_id');

        if ($phoneNumberId === '') {
            throw new RuntimeException('KIRIMDEV_PHONE_NUMBER_ID belum diisi di .env');
        }

        $url = rtrim((string) config('services.kirimdev.base_url'), '/')
            . "/v1/{$phoneNumberId}/messages";

        /** @var Response $response */
        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout((int) config('services.kirimdev.timeout', 15))
            ->post($url, $payload);

        $result = $response->json() ?? [];

        if ($response->failed()) {
            Log::channel(config('services.kirimdev.log_channel', 'kirimdev'))
                ->error('Gagal kirim pesan ke Kirimdev', [
                    'status' => $response->status(),
                    'url' => $url,
                    'to' => $payload['to'] ?? null,
                    'response' => $result ?: $response->body(),
                ]);

            $response->throw();
        }

        Log::channel(config('services.kirimdev.log_channel', 'kirimdev'))
            ->info('Pesan terkirim ke WhatsApp', [
                'to' => $payload['to'] ?? null,
                'type' => $payload['type'] ?? null,
                'response' => $result,
            ]);

        return $result;
    }

    /**
     * Kirimdev/Meta menerima format internasional. Payload masuk memberi
     * "6285155095022", contoh dokumentasi memakai "+628...", jadi kita
     * normalkan ke bentuk berawalan "+".
     */
    public function normalizeRecipient(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            throw new RuntimeException('Nomor tujuan tidak valid: ' . $phone);
        }

        // 08xxx -> 628xxx
        if (str_starts_with($digits, '0')) {
            $digits = '62' . substr($digits, 1);
        }

        return '+' . $digits;
    }
}
