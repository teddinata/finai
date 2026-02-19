<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiAnalysisService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
    }

    /**
     * Analyze financial data using Gemini API
     */
    public function analyze(array $data, string $periodLabel): string
    {
        if (empty($this->apiKey)) {
            Log::error('Gemini API Key is missing');
            return 'Maaf, konfigurasi AI belum lengkap. Harap hubungi administrator.';
        }

        $prompt = $this->buildPrompt($data, $periodLabel);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(60)->post("{$this->baseUrl}?key={$this->apiKey}", [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ]
            ]);

            if ($response->successful()) {
                $result = $response->json();
                return $result['candidates'][0]['content']['parts'][0]['text'] ?? 'Maaf, saya tidak dapat menghasilkan analisis saat ini.';
            }

            Log::error('Gemini API Error: ' . $response->body());

            if ($response->status() === 429) {
                return 'Maaf, batas penggunaan AI harian telah tercapai. Silakan coba lagi nanti.';
            }

            return 'Maaf, terjadi kesalahan saat menghubungi layanan AI.';
        }
        catch (\Exception $e) {
            Log::error('AI Analysis Exception: ' . $e->getMessage());
            return 'Maaf, terjadi kesalahan internal saat memproses analisis.';
        }
    }

    /**
     * Build the prompt for the AI
     */
    protected function buildPrompt(array $data, string $periodLabel): string
    {
        $jsonData = json_encode($data, JSON_PRETTY_PRINT);

        return "Anda adalah asisten keuangan pribadi yang cerdas dan empatik bernama FinAI by Benah. 
        
Tugas Anda adalah menganalisis kesehatan keuangan pengguna secara SUPER HOLISTIK berdasarkan data berikut:

{$jsonData}

Berikan analisis yang MENDALAM dan TERINTEGRASI (jangan hanya membaca ulang angka). Hubungkan titik-titik data.
Bahasa: Indonesia santai, suportif, tapi tajam dan profesional.

Struktur Analisis:
1.  **Big Picture (Kesehatan Umum)**: 
    - Apakah surplus/defisit? 
    - Bagaimana cashflow bulan ini?
2.  **Analisis Pengeluaran & Budgeting**: 
    - Apakah ada kategori yang boros? 
    - Bagaimana status Budget mereka? Ada yang over? Beri peringatan halus.
3.  **Progres Kekayaan (Savings & Investments)**: 
    - Apakah mereka rajin menabung? 
    - Bagaimana performa investasi mereka (Profit/Loss)? Beri apresiasi atau semangat.
4.  **Kewajiban Mendatang (Recurring)**: 
    - Ingatkan jika ada tagihan rutin yang akan datang dan perlu disiapkan dananya.
5.  **Rekomendasi Strategis (Actionable Insights)**:
    - Berikan 3 saran konkret yang menghubungkan income, expense, dan goals mereka. 
    - Contoh: 'Karena budget makan sudah over, kurangi jajan minggu ini demi mencapai target Tabungan Rumah.'

Gunakan format markdown yang rapi. Jadilah konsultan keuangan pribadi mereka.";
    }
}