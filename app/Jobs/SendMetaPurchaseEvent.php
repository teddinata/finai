<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\Meta\MetaConversionsApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kirim event Purchase ke Meta Conversions API untuk satu pembayaran yang lunas.
 * Aman dipanggil berkali-kali: pembayaran yang sudah terkirim dilewati.
 */
class SendMetaPurchaseEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $paymentId)
    {
    }

    /**
     * Dipanggil dari XenditService::handlePaymentSuccess. Tidak pernah melempar error,
     * supaya aktivasi langganan tidak ikut gagal kalau Meta bermasalah.
     */
    public static function dispatchFor(Payment $payment, ?string $paidVia = null): void
    {
        try {
            if (!app(MetaConversionsApi::class)->isEnabled()) {
                return;
            }

            // Ditandai lunas manual oleh admin biasanya untuk uji coba, bukan pembelian dari iklan
            if ($paidVia === 'MANUAL_ADMIN' || (int) $payment->total <= 0) {
                return;
            }

            if (config('services.meta.queue')) {
                static::dispatch($payment->id)->afterCommit();
            } else {
                // Dijalankan setelah respons ke Xendit terkirim; tidak butuh queue worker
                static::dispatchAfterResponse($payment->id);
            }
        } catch (Throwable $e) {
            Log::warning('Meta CAPI: gagal menjadwalkan event Purchase', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handle(MetaConversionsApi $capi): void
    {
        if (!$capi->isEnabled()) {
            return;
        }

        $payment = Payment::with(['user', 'subscription.plan'])->find($this->paymentId);

        if (!$payment || !$payment->isPaid() || (int) $payment->total <= 0) {
            return;
        }

        if (!empty($payment->metadata['meta_capi']['purchase_sent_at'])) {
            return;
        }

        $event = $capi->purchaseEvent($payment);

        try {
            $response = $capi->send([$event]);
        } catch (Throwable $e) {
            Log::warning('Meta CAPI: event Purchase gagal dikirim', [
                'payment_id' => $payment->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            $this->recordResult($payment, ['last_error' => mb_substr($e->getMessage(), 0, 500)]);

            // Lewat queue sungguhan: lempar lagi supaya Laravel mencoba ulang.
            if ($this->job && $this->job->getConnectionName() !== 'sync') {
                throw $e;
            }

            return;
        }

        $this->recordResult($payment, [
            'purchase_sent_at' => now()->toIso8601String(),
            'event_id' => $event['event_id'],
            'events_received' => $response['events_received'] ?? null,
            'fbtrace_id' => $response['fbtrace_id'] ?? null,
            'test_event_code' => config('services.meta.test_event_code') ?: null,
            'last_error' => null,
        ]);

        Log::info('Meta CAPI: event Purchase terkirim', [
            'payment_id' => $payment->id,
            'events_received' => $response['events_received'] ?? null,
        ]);
    }

    private function recordResult(Payment $payment, array $result): void
    {
        $payment->refresh();

        $metadata = $payment->metadata ?? [];
        $metadata['meta_capi'] = array_merge($metadata['meta_capi'] ?? [], $result);

        $payment->update(['metadata' => $metadata]);
    }
}
