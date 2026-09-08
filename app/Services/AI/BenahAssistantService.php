<?php

namespace App\Services\AI;

use App\Models\Plan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Asisten tanya-jawab produk Benah lewat WhatsApp.
 *
 * Bedanya dengan pemakaian Gemini di TransactionController: di sana output
 * harus JSON untuk dijadikan transaksi, di sini output berupa teks bebas
 * yang langsung dikirim ke pelanggan.
 *
 * Fakta harga & fitur diambil dari tabel plans, bukan dari ingatan model,
 * supaya AI tidak mengarang paket atau harga yang tidak ada.
 */
class BenahAssistantService
{
    /** Riwayat percakapan disimpan per nomor WhatsApp. */
    private const HISTORY_PREFIX = 'kirimdev:chat:';

    /** Berapa pesan terakhir (user + AI) yang diingat. */
    private const HISTORY_LIMIT = 10;

    private const HISTORY_TTL = 3600;

    /**
     * Jawab pertanyaan pelanggan.
     *
     * @param  string  $question  Pesan dari WhatsApp
     * @param  string  $conversationKey  Nomor WA / conversation id, untuk memori percakapan
     */
    public function answer(string $question, string $conversationKey, ?string $customerName = null): string
    {
        $question = trim($question);

        if ($question === '') {
            return 'Maaf, pesannya kosong. Boleh tulis pertanyaannya?';
        }

        $history = $this->history($conversationKey);

        $contents = $history;
        $contents[] = ['role' => 'user', 'parts' => [['text' => $question]]];

        $answer = $this->ask($contents, $customerName);

        $this->rememberTurn($conversationKey, $question, $answer);

        return $answer;
    }

    /**
     * Kosongkan memori percakapan (dipakai kalau pelanggan mengetik "reset").
     */
    public function forget(string $conversationKey): void
    {
        Cache::forget(self::HISTORY_PREFIX . $conversationKey);
    }

    /**
     * @param  array<int, array<string, mixed>>  $contents
     */
    private function ask(array $contents, ?string $customerName): string
    {
        $apiKey = trim((string) config('services.gemini.api_key'));

        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY belum diisi di .env');
        }

        $model = (string) config('services.gemini.model', 'gemini-2.5-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $response = Http::timeout((int) config('services.gemini.timeout', 30))
            ->post($url, [
                'system_instruction' => [
                    'parts' => [['text' => $this->systemPrompt($customerName)]],
                ],
                'contents' => $contents,
                'generationConfig' => [
                    // Sedikit lebih luwes dari ekstraksi transaksi (0.1) karena
                    // ini percakapan, tapi tetap rendah supaya tidak melantur.
                    'temperature' => 0.3,
                    'maxOutputTokens' => 1024,
                ],
                'safetySettings' => [],
            ]);

        if ($response->status() === 429) {
            Log::channel($this->logChannel())->warning('Kuota Gemini habis saat menjawab WhatsApp');

            return 'Maaf, asisten sedang ramai dipakai. Coba kirim ulang pertanyaannya beberapa saat lagi ya.';
        }

        if ($response->failed()) {
            Log::channel($this->logChannel())->error('Gemini gagal menjawab', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 800),
            ]);

            throw new RuntimeException('Gemini API error: HTTP ' . $response->status());
        }

        $result = $response->json();
        $text = data_get($result, 'candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            $finishReason = data_get($result, 'candidates.0.finishReason');

            Log::channel($this->logChannel())->error('Jawaban Gemini kosong', [
                'finish_reason' => $finishReason,
                'result' => Str::limit(json_encode($result), 800),
            ]);

            return 'Maaf, saya belum bisa menjawab yang itu. Boleh ditanyakan dengan kalimat lain?';
        }

        return $this->trimForWhatsapp($text);
    }

    /**
     * Instruksi sistem + basis pengetahuan produk.
     */
    private function systemPrompt(?string $customerName): string
    {
        $sapaan = $customerName ? "Nama pelanggan yang sedang chat: {$customerName}." : '';

        return <<<PROMPT
        Kamu adalah asisten resmi Benah, aplikasi pencatatan dan pengelolaan keuangan
        rumah tangga asal Indonesia. Kamu menjawab pelanggan lewat WhatsApp.

        {$sapaan}

        CARA MENJAWAB
        - Selalu Bahasa Indonesia yang ramah, sopan, dan santai. Boleh pakai "kak".
        - Singkat, maksimal 4 kalimat atau beberapa poin pendek. Ini WhatsApp, bukan email.
        - Jangan pakai markdown heading atau tabel.
        - Kalau perlu daftar, tiap baris diawali tanda hubung "-". JANGAN pernah
          memakai bintang "*" sebagai penanda daftar, karena di WhatsApp bintang
          adalah penanda tebal dan tampilannya jadi berantakan.
        - Untuk penekanan pakai *tebal* satu bintang ala WhatsApp, bukan **dobel bintang**.
        - Sebut harga dalam format rupiah, contoh: Rp49.000/bulan.

        BATASAN PENTING
        - Jawab HANYA berdasarkan DATA PRODUK di bawah. Jangan mengarang paket,
          harga, promo, diskon, atau fitur yang tidak tercantum.
        - Kalau ditanya hal yang tidak ada datanya (misalnya status pembayaran
          pribadi, refund, atau kendala teknis akun), jangan menebak. Katakan kamu
          akan sambungkan ke tim Benah.
        - Jangan meminta password, OTP, PIN, atau data kartu.
        - Kalau pertanyaannya di luar topik keuangan/produk Benah, arahkan dengan
          sopan kembali ke topik Benah.

        DATA PRODUK BENAH
        {$this->productKnowledge()}

        FITUR UTAMA APLIKASI
        - Catat pemasukan & pengeluaran, termasuk lewat chat AI.
        - Scan struk belanja dengan AI, hasilnya langsung jadi transaksi.
        - Multi akun (kas, bank, e-wallet) dan transfer antar akun.
        - Anggaran/budget per kategori, target tabungan, transaksi berulang.
        - Catatan utang/cicilan dan investasi, plus laporan kekayaan bersih.
        - Mode rumah tangga: satu keluarga bisa berbagi catatan keuangan.
        PROMPT;
    }

    /**
     * Ringkasan paket aktif dari database, di-cache 10 menit supaya tidak
     * query berulang tiap pesan masuk.
     */
    private function productKnowledge(): string
    {
        return Cache::remember('kirimdev:plan-knowledge', 600, function () {
            $plans = Plan::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            if ($plans->isEmpty()) {
                return '(Data paket belum tersedia. Arahkan pelanggan ke tim Benah.)';
            }

            return $plans->map(function (Plan $plan) {
                $baris = ["Paket {$plan->name} ({$plan->type})"];

                $baris[] = '  Harga bulanan: ' . $this->rupiah($plan->discount_price ?? $plan->price)
                    . ($plan->discount_price !== null && $plan->discount_price < $plan->price
                        ? ' (harga normal ' . $this->rupiah($plan->price) . ')'
                        : '');

                if ($plan->price_yearly !== null) {
                    $baris[] = '  Harga tahunan: ' . $this->rupiah($plan->discount_price_yearly ?? $plan->price_yearly);
                }

                if ($plan->description) {
                    $baris[] = '  Deskripsi: ' . $plan->description;
                }

                $baris[] = '  Batasan & fitur: ' . $this->describeFeatures($plan->features);

                return implode("\n", $baris);
            })->implode("\n\n");
        });
    }

    /**
     * Ubah JSON features jadi kalimat yang mudah dibaca model.
     *
     * @param  mixed  $features
     */
    private function describeFeatures($features): string
    {
        if (! is_array($features) || $features === []) {
            return 'tidak ada detail fitur.';
        }

        $label = [
            'max_users' => 'jumlah pengguna',
            'max_accounts' => 'jumlah akun',
            'max_transactions_per_month' => 'transaksi per bulan',
            'max_ai_scans_per_month' => 'scan struk AI per bulan',
            'storage_mb' => 'penyimpanan (MB)',
            'reports' => 'laporan',
            'analytics' => 'analitik',
            'budget' => 'anggaran',
            'assets' => 'aset/investasi',
            'debts' => 'utang & cicilan',
            'recurring_transactions' => 'transaksi berulang',
            'invite_members' => 'undang anggota keluarga',
            'export_data' => 'ekspor data',
            'multi_currency' => 'multi mata uang',
            'priority_support' => 'dukungan prioritas',
            'custom_categories' => 'kategori kustom',
            'web_access' => 'akses web',
            'api_access' => 'akses API',
            'notifications' => 'notifikasi',
        ];

        $parts = [];

        foreach ($features as $key => $value) {
            $name = $label[$key] ?? str_replace('_', ' ', (string) $key);

            if (is_bool($value)) {
                $parts[] = $name . ': ' . ($value ? 'ada' : 'tidak ada');
                continue;
            }

            if (is_numeric($value)) {
                $parts[] = $name . ': ' . ((int) $value === -1 ? 'tanpa batas' : $value);
                continue;
            }

            // Sebagian fitur berisi array, contoh budget_templates: ["50/30/20"].
            if (is_array($value)) {
                $parts[] = $name . ': ' . implode(', ', array_map(
                    fn ($item) => is_scalar($item) ? (string) $item : json_encode($item),
                    $value
                ));
                continue;
            }

            if ($value === null) {
                $parts[] = $name . ': tidak ada';
                continue;
            }

            $parts[] = $name . ': ' . (string) $value;
        }

        return implode(', ', $parts) . '.';
    }

    private function rupiah($amount): string
    {
        if ($amount === null) {
            return 'tidak tersedia';
        }

        if ((float) $amount == 0.0) {
            return 'Gratis';
        }

        return 'Rp' . number_format((float) $amount, 0, ',', '.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function history(string $conversationKey): array
    {
        $history = Cache::get(self::HISTORY_PREFIX . $conversationKey, []);

        return is_array($history) ? $history : [];
    }

    private function rememberTurn(string $conversationKey, string $question, string $answer): void
    {
        try {
            $history = $this->history($conversationKey);
            $history[] = ['role' => 'user', 'parts' => [['text' => $question]]];
            $history[] = ['role' => 'model', 'parts' => [['text' => $answer]]];

            Cache::put(
                self::HISTORY_PREFIX . $conversationKey,
                array_slice($history, -self::HISTORY_LIMIT),
                self::HISTORY_TTL
            );
        } catch (\Throwable $e) {
            Log::channel($this->logChannel())->warning('Gagal menyimpan riwayat chat', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * WhatsApp membatasi 4096 karakter, tapi jawaban panjang juga tidak enak
     * dibaca di HP.
     */
    private function trimForWhatsapp(string $text): string
    {
        $text = trim($text);
        // Rapikan markdown **tebal** jadi *tebal* ala WhatsApp.
        $text = preg_replace('/\*\*(.+?)\*\*/s', '*$1*', $text) ?? $text;
        // Model kadang tetap memakai "* " sebagai bullet; di WhatsApp itu
        // tabrakan dengan penanda tebal, jadi diganti "- ".
        $text = preg_replace('/^[ \t]*\*[ \t]+/m', '- ', $text) ?? $text;
        // Buang heading markdown kalau lolos juga.
        $text = preg_replace('/^#{1,6}[ \t]*/m', '', $text) ?? $text;

        return Str::limit($text, 1500, '…');
    }

    private function logChannel(): string
    {
        return (string) config('services.kirimdev.log_channel', 'kirimdev');
    }
}
