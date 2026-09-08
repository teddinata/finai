<?php

namespace App\Jobs;

use App\Services\Kirimdev\KirimdevReplyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Balas pesan WhatsApp di luar request webhook.
 *
 * Kirimdev memutus koneksi setelah 10 detik dan me-retry sampai 8 kali,
 * sementara Gemini butuh beberapa detik. Dengan job ini webhook bisa
 * langsung balas 200 dan pekerjaan beratnya dikerjakan worker.
 *
 * Aktifkan lewat KIRIMDEV_QUEUE=true dan pastikan `php artisan queue:work`
 * berjalan (supervisor).
 */
class ReplyKirimdevMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $parsed
     */
    public function __construct(public array $parsed)
    {
    }

    public function handle(KirimdevReplyService $replies): void
    {
        $replies->replyTo($this->parsed);
    }
}
