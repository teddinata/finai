<?php

namespace App\Services\Meta;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;

/**
 * Meta Conversions API (event dari server).
 *
 * Dipakai bersama Meta Pixel di frontend. Event dengan event_name dan event_id
 * yang sama dari browser dan server digabung Meta menjadi satu, jadi satu
 * pembelian tidak terhitung dua kali.
 *
 * Dok: https://developers.facebook.com/docs/marketing-api/conversions-api
 */
class MetaConversionsApi
{
    public function isEnabled(): bool
    {
        return filled(config('services.meta.pixel_id')) && filled(config('services.meta.access_token'));
    }

    /**
     * Harus sama dengan eventID di frontend (src/utils/analytics.js, trackPurchase).
     */
    public static function purchaseEventId(int|string $paymentId): string
    {
        return 'purchase-' . $paymentId;
    }

    public function purchaseEvent(Payment $payment): array
    {
        $payment->loadMissing(['user', 'subscription.plan']);

        $tracking = $payment->metadata['meta_tracking'] ?? [];
        $plan = $payment->subscription?->plan;

        return self::buildPurchaseEvent([
            'payment_id' => $payment->id,
            'value' => (int) $payment->total,
            'currency' => $payment->currency ?: 'IDR',
            'event_time' => ($payment->paid_at ?? now())->getTimestamp(),
            'plan_id' => $plan?->id,
            'plan_name' => $plan?->name,
            'email' => $payment->user?->email,
            'name' => $payment->user?->name,
            'user_id' => $payment->user_id,
            'fbp' => $tracking['fbp'] ?? null,
            'fbc' => $tracking['fbc'] ?? null,
            'client_ip_address' => $tracking['client_ip_address'] ?? null,
            'client_user_agent' => $tracking['client_user_agent'] ?? null,
            'event_source_url' => $tracking['event_source_url']
                ?? rtrim((string) config('app.frontend_url'), '/') . '/payment/success/' . $payment->id,
        ]);
    }

    /**
     * Bentuk satu event Purchase. Fungsi murni (tanpa database dan config) supaya mudah diuji.
     * Email, nama, dan user id di-hash SHA-256 sesuai aturan Meta; IP, user agent,
     * fbp, dan fbc dikirim apa adanya.
     */
    public static function buildPurchaseEvent(array $data): array
    {
        [$firstName, $lastName] = self::splitName($data['name'] ?? null);

        $userData = array_filter([
            'em' => self::hashList($data['email'] ?? null),
            'fn' => self::hashList($firstName),
            'ln' => self::hashList($lastName),
            'external_id' => self::hashList(isset($data['user_id']) ? (string) $data['user_id'] : null),
            'country' => self::hashList('id'),
            'client_ip_address' => $data['client_ip_address'] ?? null,
            'client_user_agent' => $data['client_user_agent'] ?? null,
            'fbp' => $data['fbp'] ?? null,
            'fbc' => $data['fbc'] ?? null,
        ]);

        $customData = array_filter([
            'currency' => strtoupper($data['currency'] ?? 'IDR'),
            'value' => (int) ($data['value'] ?? 0),
            'content_type' => 'product',
            'content_ids' => isset($data['plan_id']) ? [(string) $data['plan_id']] : null,
            'content_name' => $data['plan_name'] ?? null,
            'num_items' => 1,
            'order_id' => (string) $data['payment_id'],
        ], fn ($value) => $value !== null);

        return array_filter([
            'event_name' => 'Purchase',
            'event_time' => (int) $data['event_time'],
            'event_id' => self::purchaseEventId($data['payment_id']),
            'action_source' => 'website',
            'event_source_url' => $data['event_source_url'] ?? null,
            'user_data' => $userData,
            'custom_data' => $customData,
        ], fn ($value) => $value !== null);
    }

    /**
     * Kirim event ke Graph API.
     *
     * @return array contoh: ['events_received' => 1, 'fbtrace_id' => '...']
     *
     * @throws \Illuminate\Http\Client\RequestException kalau Meta menolak request
     */
    public function send(array $events): array
    {
        $payload = [
            'data' => $events,
            'access_token' => config('services.meta.access_token'),
        ];

        if ($testCode = config('services.meta.test_event_code')) {
            $payload['test_event_code'] = $testCode;
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/events',
            config('services.meta.graph_version', 'v23.0'),
            config('services.meta.pixel_id')
        );

        return Http::timeout((int) config('services.meta.timeout', 10))
            ->asJson()
            ->post($url, $payload)
            ->throw()
            ->json() ?? [];
    }

    private static function hashList(?string $value): ?array
    {
        $normalized = mb_strtolower(trim((string) $value));

        return $normalized === '' ? null : [hash('sha256', $normalized)];
    }

    /**
     * "Budi Santoso" -> ["budi", "santoso"]. Nama satu kata hanya mengisi nama depan.
     */
    private static function splitName(?string $name): array
    {
        $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $clean = fn (string $part) => preg_replace('/[\p{P}\p{S}]+/u', '', $part);

        $first = $parts ? $clean(array_shift($parts)) : '';
        $last = $parts ? $clean(array_pop($parts)) : '';

        return [$first !== '' ? $first : null, $last !== '' ? $last : null];
    }
}
