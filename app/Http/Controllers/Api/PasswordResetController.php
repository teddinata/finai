<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

class PasswordResetController extends Controller
{
    /**
     * Kirim link reset. Jawabannya selalu sama supaya endpoint ini tidak bisa
     * dipakai untuk mengecek email mana yang terdaftar.
     */
    public function forgot(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ], [
            'email.required' => 'Masukkan email akunmu.',
            'email.email' => 'Masukkan email yang valid, misalnya nama@gmail.com.',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user && $user->active) {
            try {
                Password::sendResetLink(['email' => $user->email]);
            }
            catch (\Throwable $e) {
                Log::warning('Password reset email failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Kalau email itu terdaftar, link untuk membuat password baru sudah dikirim.',
        ]);
    }

    public function reset(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'password.min' => 'Password minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak sama.',
        ]);

        $status = Password::reset($validated, function (User $user, string $password) {
            $user->password = $password;

            // Membuka link dari email sekaligus membuktikan email itu milik pengguna
            if (! $user->hasVerifiedEmail()) {
                $user->email_verified_at = now();
            }

            $user->save();

            // Keluarkan semua sesi lama, termasuk yang mungkin dipakai orang lain
            $user->tokens()->delete();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Link sudah tidak berlaku atau kedaluwarsa. Minta link baru dari halaman lupa password.',
            ], 422);
        }

        return response()->json([
            'message' => 'Password berhasil diganti. Silakan masuk dengan password baru.',
        ]);
    }
}
