<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Endpoint testing webhook Kirimdev (WhatsApp).
 *
 * Tujuan controller ini HANYA membuktikan jalur:
 *
 *     WhatsApp -> Meta -> Kirimdev -> Laravel
 *
 * Belum ada Gemini, belum ada simpan transaksi. Semua payload yang masuk
 * di-log ke storage/logs/kirimdev.log dan disimpan di cache supaya bisa
 * dilihat lewat GET /api/webhooks/kirimdev-test/last tanpa perlu SSH.
 */
class KirimdevWebhookController extends Controller
{
    /** Cache key penampung payload terakhir (buat debug tanpa buka log). */
    private const CACHE_KEY = 'kirimdev:webhook:recent';

    /** Berapa payload terakhir yang disimpan. */
    private const CACHE_LIMIT = 20;

    /** Payload disimpan 1 jam saja, ini cuma alat bantu development. */
    private const CACHE_TTL = 3600;

    /**
     * POST /api/webhooks/kirimdev-test
     *
     * Terima apa pun dari Kirimdev, log, lalu balas 200 secepatnya.
     * Selalu 200 kecuali signature enforcement dinyalakan dan gagal, supaya
     * Kirimdev tidak retry terus-menerus selama masa testing.
     */
    public function test(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $payload = $this->decodePayload($rawBody);
        $signature = $this->checkSignature($request, $rawBody);
        $event = $this->eventName($request, $payload);
        $parsed = $this->parseMessage($payload);

        $record = [
            'received_at' => now()->toIso8601String(),
            'event' => $event,
            'event_id' => $this->header($request, ['X-Kirim-Event-Id', 'X-Kirim-Id']),
            // Delivery id + attempt dipakai membedakan kiriman ulang Kirimdev
            // dari pesan baru (nanti berguna untuk idempotency).
            'delivery_id' => $this->header($request, ['X-Kirim-Delivery-Id']),
            'attempt' => $this->header($request, ['X-Kirim-Attempt']),
            'source' => $this->header($request, ['X-Kirim-Source']),
            'signature' => $signature,
            'message' => $parsed,
            'headers' => $this->safeHeaders($request),
            'payload' => $payload,
        ];

        // Monolog memotong array di kedalaman 9 ("Over 9 levels deep, aborting
        // normalization"), padahal payload Meta lebih dalam dari itu. Jadi ke
        // log kita kirim raw body-nya sebagai string supaya utuh.
        $this->logChannel()->info('Kirimdev webhook received', [
            'payload_raw' => $rawBody,
        ] + array_diff_key($record, ['payload' => null]));

        $this->remember($record);

        if ($this->enforceSignature() && ! $signature['verified']) {
            $this->logChannel()->warning('Kirimdev webhook ditolak: signature invalid', [
                'reason' => $signature['reason'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid signature',
                'reason' => $signature['reason'],
            ], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'Webhook received',
            'event' => $event,
            'signature' => $signature,
            'parsed' => $parsed,
        ]);
    }

    /**
     * GET /api/webhooks/kirimdev-test
     *
     * Dipakai untuk dua hal:
     * 1. Cek cepat lewat browser bahwa URL-nya hidup dan reachable.
     * 2. Menjawab verification challenge gaya Meta (hub.challenge) kalau
     *    Kirimdev meneruskannya saat webhook didaftarkan.
     */
    public function verify(Request $request)
    {
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        if ($challenge !== null) {
            $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
            $expected = (string) config('services.kirimdev.verify_token');

            $this->logChannel()->info('Kirimdev webhook verification challenge', [
                'token_match' => $expected === '' ? 'no_token_configured' : hash_equals($expected, (string) $token),
            ]);

            if ($expected !== '' && ! hash_equals($expected, (string) $token)) {
                return response('Invalid verify token', 403);
            }

            return response((string) $challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        return response()->json([
            'success' => true,
            'message' => 'Kirimdev test webhook endpoint aktif. Kirim POST ke URL ini.',
            'endpoint' => $request->fullUrl(),
            'signature_enforced' => $this->enforceSignature(),
            'secret_configured' => $this->secret() !== '',
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/webhooks/kirimdev-test/last
     *
     * Lihat payload terakhir tanpa tail log. Payload berisi nomor WhatsApp
     * pelanggan, jadi endpoint ini dikunci: hanya jalan di environment local
     * atau kalau KIRIMDEV_DEBUG_TOKEN cocok.
     */
    public function last(Request $request): JsonResponse
    {
        if (! $this->allowDebugAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Debug endpoint terkunci. Set KIRIMDEV_DEBUG_TOKEN lalu kirim ?token=... atau header X-Debug-Token.',
            ], 403);
        }

        $records = Cache::get(self::CACHE_KEY, []);

        return response()->json([
            'success' => true,
            'count' => count($records),
            'data' => $records,
        ]);
    }

    /**
     * DELETE /api/webhooks/kirimdev-test/last
     */
    public function flush(Request $request): JsonResponse
    {
        if (! $this->allowDebugAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Debug endpoint terkunci.',
            ], 403);
        }

        Cache::forget(self::CACHE_KEY);

        return response()->json([
            'success' => true,
            'message' => 'Riwayat payload dikosongkan.',
        ]);
    }

    // =========================================================================
    //  SIGNATURE
    // =========================================================================

    /**
     * Verifikasi HMAC SHA-256 Kirimdev.
     *
     * Format header: "t=<unix_timestamp>,v1=<hex_signature>"
     * Signed payload: "<timestamp>.<raw_body>"
     *
     * Selama smoke test hasilnya cuma dilaporkan (tidak memblokir), kecuali
     * KIRIMDEV_ENFORCE_SIGNATURE=true.
     */
    private function checkSignature(Request $request, string $rawBody): array
    {
        $header = $this->header($request, ['X-Kirim-Signature', 'X-Kirimdev-Signature']);
        $secret = $this->secret();

        if ($secret === '') {
            return $this->signatureResult(false, 'secret_not_configured', $header);
        }

        if (! $header) {
            return $this->signatureResult(false, 'signature_header_missing', $header);
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $part = trim($part);

            if (str_starts_with($part, 't=')) {
                $timestamp = substr($part, 2);
                continue;
            }

            if (str_starts_with($part, 'v1=')) {
                $signatures[] = substr($part, 3);
            }
        }

        // Sebagian provider mengirim hex polos tanpa skema t=/v1=.
        if ($signatures === [] && preg_match('/^[a-f0-9]{64}$/i', $header)) {
            $signatures[] = $header;
        }

        if ($signatures === []) {
            return $this->signatureResult(false, 'signature_format_unknown', $header);
        }

        $tolerance = (int) config('services.kirimdev.signature_tolerance', 300);

        if ($timestamp !== null && $tolerance > 0 && abs(time() - (int) $timestamp) > $tolerance) {
            return $this->signatureResult(false, 'timestamp_out_of_tolerance', $header);
        }

        // Tanpa t= berarti signed payload-nya raw body saja.
        $signedPayload = $timestamp !== null ? $timestamp . '.' . $rawBody : $rawBody;
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return $this->signatureResult(true, 'ok', $header);
            }
        }

        return $this->signatureResult(false, 'signature_mismatch', $header);
    }

    private function signatureResult(bool $verified, string $reason, ?string $header): array
    {
        return [
            'verified' => $verified,
            'reason' => $reason,
            'enforced' => $this->enforceSignature(),
            'header_present' => $header !== null && $header !== '',
        ];
    }

    private function secret(): string
    {
        return trim((string) config('services.kirimdev.webhook_secret'));
    }

    private function enforceSignature(): bool
    {
        return (bool) config('services.kirimdev.enforce_signature', false) && $this->secret() !== '';
    }

    // =========================================================================
    //  PARSER
    // =========================================================================

    /**
     * Ambil bagian yang penting dari payload, apa pun bentuk envelope-nya.
     *
     * Struktur Kirimdev mengikuti Meta (entry[].changes[].value), tapi
     * beberapa event dibungkus "data". Semua kemungkinan dicoba supaya
     * smoke test pertama tidak gagal cuma karena tebakan path salah.
     */
    private function parseMessage(array $payload): array
    {
        $value = $this->firstOf($payload, [
            'entry.0.changes.0.value',
            'data.entry.0.changes.0.value',
            'payload.entry.0.changes.0.value',
            'data.value',
            'value',
            'data',
        ]) ?? $payload;

        $value = is_array($value) ? $value : [];

        $message = $this->firstOf($value, ['messages.0', 'message'])
            ?? $this->firstOf($payload, ['messages.0', 'message'])
            ?? [];

        $message = is_array($message) ? $message : [];

        $status = $this->firstOf($value, ['statuses.0']);
        $type = $this->firstOf($message, ['type']);

        return [
            'phone_number_id' => $this->firstOf($value, [
                'metadata.phone_number_id',
                'metadata.phoneNumberId',
                'phone_number_id',
            ]) ?? $this->firstOf($payload, ['kirim.phone_number_id', 'phone_number_id']),
            'display_phone_number' => $this->firstOf($value, ['metadata.display_phone_number']),
            'from' => $this->firstOf($message, ['from', 'sender.phone', 'sender'])
                ?? $this->firstOf($value, ['contacts.0.wa_id'])
                // Kirimdev menaruh nomor berformat "+62851..." di objek kirim.
                ?? ltrim((string) $this->firstOf($payload, ['kirim.contact.phone_number']), '+') ?: null,
            'contact_name' => $this->firstOf($value, ['contacts.0.profile.name'])
                ?? $this->firstOf($payload, ['kirim.contact.name']),
            'message_id' => $this->firstOf($message, ['id', 'message_id', 'wamid']),
            'timestamp' => $this->firstOf($message, ['timestamp']),
            'type' => $type,
            'text' => $this->firstOf($message, ['text.body', 'text', 'body', 'caption']),
            'media' => $this->parseMedia($message, $payload, $value, $type),
            'interactive' => $this->firstOf($message, ['interactive']),
            'button_reply' => $this->firstOf($message, [
                'interactive.button_reply.title',
                'interactive.list_reply.title',
                'button.text',
            ]),
            'status_update' => is_array($status) ? [
                'id' => $this->firstOf($status, ['id']),
                'status' => $this->firstOf($status, ['status']),
                'recipient' => $this->firstOf($status, ['recipient_id']),
            ] : null,
            // Dipakai nanti saat membalas lewat API Kirimdev.
            'conversation_id' => $this->firstOf($payload, ['kirim.conversation_id']),
            'kirim_message_id' => $this->firstOf($payload, ['kirim.message_id']),
            'kirim' => $this->firstOf($payload, ['kirim']) ?? $this->firstOf($value, ['kirim']),
        ];
    }

    /**
     * Detail media untuk pesan image/document/audio/video/sticker.
     */
    private function parseMedia(array $message, array $payload, array $value, ?string $type): ?array
    {
        $mediaTypes = ['image', 'document', 'audio', 'video', 'sticker'];

        if (! in_array($type, $mediaTypes, true)) {
            return null;
        }

        $media = $this->firstOf($message, [$type]);
        $media = is_array($media) ? $media : [];

        return [
            'kind' => $type,
            'id' => $this->firstOf($media, ['id', 'media_id']),
            'mime_type' => $this->firstOf($media, ['mime_type', 'mimeType']),
            'sha256' => $this->firstOf($media, ['sha256']),
            'filename' => $this->firstOf($media, ['filename']),
            'caption' => $this->firstOf($media, ['caption']) ?? $this->firstOf($message, ['caption']),
            // media_url dikirim Kirimdev kalau file sudah siap diunduh.
            'media_url' => $this->firstOf($payload, ['kirim.media_url', 'media_url'])
                ?? $this->firstOf($value, ['kirim.media_url'])
                ?? $this->firstOf($media, ['url', 'link']),
            'media_status' => $this->firstOf($payload, ['kirim.media_status'])
                ?? $this->firstOf($value, ['kirim.media_status']),
        ];
    }

    /**
     * Nama event: dari header dulu, baru fallback ke body.
     */
    private function eventName(Request $request, array $payload): ?string
    {
        $header = $this->header($request, ['X-Kirim-Event', 'X-Kirimdev-Event', 'X-Event-Name']);

        if ($header) {
            return $header;
        }

        $fromBody = $this->firstOf($payload, ['event', 'event_type', 'type', 'kirim.event']);

        return is_string($fromBody) ? $fromBody : null;
    }

    // =========================================================================
    //  HELPERS
    // =========================================================================

    private function decodePayload(string $rawBody): array
    {
        if (trim($rawBody) === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logChannel()->warning('Kirimdev webhook body bukan JSON valid', [
                'error' => json_last_error_msg(),
                'raw_preview' => Str::limit($rawBody, 500),
            ]);

            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Ambil nilai pertama yang tidak null dari beberapa kandidat path.
     */
    private function firstOf(array $source, array $paths)
    {
        foreach ($paths as $path) {
            $found = data_get($source, $path);

            if ($found !== null && $found !== '') {
                return $found;
            }
        }

        return null;
    }

    private function header(Request $request, array $names): ?string
    {
        foreach ($names as $name) {
            $value = $request->header($name);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Header untuk log, tanpa membocorkan signature/authorization utuh.
     */
    private function safeHeaders(Request $request): array
    {
        $headers = [];
        $sensitive = ['authorization', 'x-kirim-signature', 'x-kirimdev-signature', 'cookie'];

        foreach ($request->headers->all() as $key => $values) {
            $value = is_array($values) ? implode(', ', $values) : (string) $values;

            $headers[$key] = in_array(strtolower($key), $sensitive, true)
                ? Str::limit($value, 12, '...[masked]')
                : $value;
        }

        return $headers;
    }

    private function remember(array $record): void
    {
        try {
            $records = Cache::get(self::CACHE_KEY, []);
            array_unshift($records, $record);
            Cache::put(self::CACHE_KEY, array_slice($records, 0, self::CACHE_LIMIT), self::CACHE_TTL);
        } catch (\Throwable $e) {
            // Cache gagal jangan sampai bikin webhook balas 500.
            $this->logChannel()->warning('Gagal menyimpan payload Kirimdev ke cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function allowDebugAccess(Request $request): bool
    {
        $token = trim((string) config('services.kirimdev.debug_token'));

        if ($token !== '') {
            $given = (string) ($request->header('X-Debug-Token') ?? $request->query('token', ''));

            return $given !== '' && hash_equals($token, $given);
        }

        return app()->environment('local');
    }

    private function logChannel()
    {
        return Log::channel(config('services.kirimdev.log_channel', 'kirimdev'));
    }
}
