<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * Tujuan kembali lama dari Xendit (/payment/success dan /payment/failed di domain API).
 *
 * Pembayaran baru sudah diarahkan langsung ke halaman frontend (lihat
 * XenditService::redirectUrls). Ini tetap ada untuk transaksi yang dibuat sebelum
 * perbaikan itu, supaya pembeli tidak mendarat di halaman 404.
 */
class PaymentRedirectController extends Controller
{
    public function success(Request $request)
    {
        Log::info('Payment Success Redirect', [
            'query_params' => $request->all(),
        ]);

        return redirect($this->frontendTarget($request));
    }

    public function failed(Request $request)
    {
        Log::info('Payment Failed Redirect', [
            'query_params' => $request->all(),
        ]);

        return redirect($this->frontendTarget($request));
    }

    /**
     * Halaman hasil pembayaran di frontend membaca status terbaru sendiri, jadi
     * sukses maupun gagal diarahkan ke sana. Tanpa ID (Xendit tidak mengirimkannya
     * untuk e-wallet), frontend memakai pembayaran terakhir milik pengguna.
     */
    private function frontendTarget(Request $request): string
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/') . '/payment/success';

        $token = $request->query('external_id') ?? $request->query('reference_id');
        $payment = $token ? Payment::where('payment_token', $token)->first() : null;

        return $payment ? "{$frontendUrl}/{$payment->id}" : $frontendUrl;
    }
}
