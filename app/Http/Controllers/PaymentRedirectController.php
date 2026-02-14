<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class PaymentRedirectController extends Controller
{
    /**
     * Handle successful payment redirect
     */
    public function success(Request $request)
    {
        Log::info('Payment Success Redirect', [
            'query_params' => $request->all(),
        ]);

        // Xendit biasanya kirim parameter seperti ini di URL
        $externalId = $request->query('external_id'); // untuk Invoice
        $referenceId = $request->query('reference_id'); // untuk Payment Request
        
        $payment = null;
        
        // Cari payment berdasarkan external_id atau reference_id
        if ($externalId) {
            $payment = Payment::where('payment_token', $externalId)->first();
        } elseif ($referenceId) {
            $payment = Payment::where('payment_token', $referenceId)->first();
        }

        // Jika ada frontend (Vue/React), redirect ke sana
        if (config('app.frontend_url')) {
            $frontendUrl = config('app.frontend_url') . '/payment/success';
            
            if ($payment) {
                $frontendUrl .= '?payment_id=' . $payment->id . '&status=success';
            }
            
            return redirect($frontendUrl);
        }

        // Atau tampilkan view Laravel
        return view('payment.success', [
            'payment' => $payment,
        ]);
    }

    /**
     * Handle failed payment redirect
     */
    public function failed(Request $request)
    {
        Log::info('Payment Failed Redirect', [
            'query_params' => $request->all(),
        ]);

        $externalId = $request->query('external_id');
        $referenceId = $request->query('reference_id');
        
        $payment = null;
        
        if ($externalId) {
            $payment = Payment::where('payment_token', $externalId)->first();
        } elseif ($referenceId) {
            $payment = Payment::where('payment_token', $referenceId)->first();
        }

        // Jika ada frontend, redirect ke sana
        if (config('app.frontend_url')) {
            $frontendUrl = config('app.frontend_url') . '/payment/failed';
            
            if ($payment) {
                $frontendUrl .= '?payment_id=' . $payment->id . '&status=failed';
            }
            
            return redirect($frontendUrl);
        }

        // Atau tampilkan view Laravel
        return view('payment.failed', [
            'payment' => $payment,
        ]);
    }
}