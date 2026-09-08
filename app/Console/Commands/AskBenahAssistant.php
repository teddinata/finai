<?php

namespace App\Console\Commands;

use App\Services\AI\BenahAssistantService;
use Illuminate\Console\Command;

/**
 * Uji asisten produk tanpa lewat WhatsApp.
 *
 *   php artisan benah:ask "berapa harga paket premium?"
 *   php artisan benah:ask            (mode tanya jawab interaktif)
 */
class AskBenahAssistant extends Command
{
    protected $signature = 'benah:ask
        {question?* : Pertanyaan. Kosongkan untuk mode interaktif}
        {--session=cli-test : Kunci percakapan, untuk menguji memori konteks}
        {--reset : Kosongkan riwayat percakapan lebih dulu}';

    protected $description = 'Tanya asisten produk Benah lewat terminal (memakai Gemini)';

    public function handle(BenahAssistantService $assistant): int
    {
        $session = (string) $this->option('session');

        if ($this->option('reset')) {
            $assistant->forget($session);
            $this->line('Riwayat percakapan dikosongkan.');
        }

        $question = trim(implode(' ', (array) $this->argument('question')));

        if ($question !== '') {
            return $this->jawab($assistant, $session, $question);
        }

        $this->info('Mode interaktif. Ketik "keluar" untuk berhenti.');

        while (true) {
            $input = trim((string) $this->ask('Kamu'));

            if ($input === '' || in_array(strtolower($input), ['keluar', 'exit', 'quit'], true)) {
                return self::SUCCESS;
            }

            $this->jawab($assistant, $session, $input);
        }
    }

    private function jawab(BenahAssistantService $assistant, string $session, string $question): int
    {
        $start = microtime(true);

        try {
            $answer = $assistant->answer($question, $session, 'Tester');
        } catch (\Throwable $e) {
            $this->error('Gagal: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->line($answer);
        $this->line('');
        $this->comment(sprintf('(%.1f detik)', microtime(true) - $start));

        return self::SUCCESS;
    }
}
