<?php

namespace App\Services\Kirimdev;

use App\Services\AI\BenahAssistantService;
use Illuminate\Support\Facades\Log;

/**
 * Menyusun dan mengirim balasan WhatsApp.
 *
 * Dipakai dua jalur:
 * - langsung dari controller webhook (mode sinkron), dan
 * - dari job antrean (mode asinkron),
 * supaya logikanya cuma ada di satu tempat.
 */
class KirimdevReplyService
{
    public function __construct(
        private KirimdevClient $client,
        private BenahAssistantService $assistant,
    ) {
    }

    /**
     * @param  array<string, mixed>  $parsed  Hasil parse webhook
     * @return array<string, mixed>
     */
    public function replyTo(array $parsed): array
    {
        $from = (string) ($parsed['from'] ?? '');

        if ($from === '') {
            return ['sent' => false, 'reason' => 'pengirim_tidak_diketahui'];
        }

        $text = $this->composeReply($parsed);

        if ($text === null) {
            return ['sent' => false, 'reason' => 'tidak_ada_yang_perlu_dibalas'];
        }

        $wamid = (string) ($parsed['message_id'] ?? '');
        $phoneNumberId = $parsed['phone_number_id'] ? (string) $parsed['phone_number_id'] : null;

        try {
            // context.message_id wajib berisi wamid dari messages[0].id.
            // Kalau kosong, kirim biasa daripada request-nya ditolak 400.
            $response = str_starts_with($wamid, 'wamid.')
                ? $this->client->replyText($from, $text, $wamid, $phoneNumberId)
                : $this->client->sendText($from, $text, $phoneNumberId);

            return ['sent' => true, 'quoted' => str_starts_with($wamid, 'wamid.'), 'response' => $response];
        } catch (\Throwable $e) {
            Log::channel($this->logChannel())->error('Gagal mengirim balasan WhatsApp', [
                'to' => $from,
                'error' => $e->getMessage(),
            ]);

            return [
                'sent' => false,
                'reason' => 'kirim_gagal',
                'error' => $e->getMessage(),
                // Supaya saat debugging tetap kelihatan balasan apa yang tersusun.
                'reply_preview' => \Illuminate\Support\Str::limit($text, 300),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function composeReply(array $parsed): ?string
    {
        if (! config('services.kirimdev.ai_reply')) {
            return $this->echoMessage($parsed);
        }

        $type = (string) ($parsed['type'] ?? '');
        $question = trim((string) ($parsed['text'] ?? ''));

        // Media belum ditangani AI; scan struk menyusul di tahap berikutnya.
        if ($type !== 'text' || $question === '') {
            return $this->echoMessage($parsed);
        }

        $conversationKey = (string) ($parsed['conversation_id'] ?? $parsed['from']);

        if (in_array(strtolower($question), ['reset', 'mulai ulang', 'clear'], true)) {
            $this->assistant->forget($conversationKey);

            return 'Percakapan sudah direset. Silakan tanya lagi dari awal ya, Kak.';
        }

        try {
            return $this->assistant->answer(
                $question,
                $conversationKey,
                $parsed['contact_name'] ? (string) $parsed['contact_name'] : null
            );
        } catch (\Throwable $e) {
            Log::channel($this->logChannel())->error('Asisten AI gagal menjawab', [
                'error' => $e->getMessage(),
            ]);

            return 'Maaf, asisten sedang bermasalah. Tim Benah akan membantu sebentar lagi ya, Kak.';
        }
    }

    /**
     * Balasan tanpa AI, dipakai saat KIRIMDEV_AI_REPLY masih false atau
     * pesannya bukan teks.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function echoMessage(array $parsed): string
    {
        $type = (string) ($parsed['type'] ?? 'unknown');

        if ($type === 'text') {
            $isi = '"' . $parsed['text'] . '"';
        } elseif ($parsed['media'] ?? null) {
            $isi = strtoupper((string) $parsed['media']['kind'])
                . ' (' . ($parsed['media']['mime_type'] ?? 'tipe tidak diketahui') . ')'
                . ' - media_status: ' . ($parsed['media']['media_status'] ?? 'null');
        } else {
            $isi = 'pesan tipe ' . $type;
        }

        return implode("\n", [
            '[TEST] Webhook Benah aktif.',
            '',
            'Saya menerima: ' . $isi,
            'Waktu server: ' . now()->timezone('Asia/Jakarta')->format('d M Y H:i') . ' WIB',
            '',
            'Ini balasan otomatis untuk menguji koneksi.',
        ]);
    }

    private function logChannel(): string
    {
        return (string) config('services.kirimdev.log_channel', 'kirimdev');
    }
}
