<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class EmailVerificationController extends Controller
{
    /**
     * Kirim link verifikasi. Dipanggil dari pengingat di aplikasi, bukan
     * syarat untuk memakai Benah.
     */
    public function send(Request $request)
    {
        return $this->dispatchLink($request->user());
    }

    /**
     * Link dari email. Selalu diarahkan ke halaman frontend, termasuk saat link
     * kedaluwarsa atau rusak, supaya pengguna tidak melihat halaman error JSON.
     */
    public function verify(Request $request, string $id, string $hash)
    {
        $target = rtrim(config('app.frontend_url'), '/') . '/email-verified';

        if (! $request->hasValidSignature()) {
            return redirect($target . '?status=expired');
        }

        $user = User::find($id);

        if (! $user || ! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            return redirect($target . '?status=invalid');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect($target . '?status=already-verified');
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return redirect($target . '?status=success');
    }

    /**
     * Check verification status
     */
    public function status(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'is_verified' => $user->hasVerifiedEmail(),
            'email' => $user->email,
            'verified_at' => $user->email_verified_at,
        ]);
    }

    /**
     * Kirim ulang link verifikasi
     */
    public function resend(Request $request)
    {
        return $this->dispatchLink($request->user());
    }

    private function dispatchLink(User $user)
    {
        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email sudah terverifikasi.',
            ], 400);
        }

        try {
            $user->sendEmailVerificationNotification();
        }
        catch (\Throwable $e) {
            Log::warning('Verification email failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Email verifikasi belum bisa dikirim. Coba lagi beberapa menit lagi.',
            ], 503);
        }

        return response()->json([
            'message' => 'Link verifikasi sudah dikirim.',
            'email' => $user->email,
        ]);
    }
}
