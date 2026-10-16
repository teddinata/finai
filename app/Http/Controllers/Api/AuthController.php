<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Household;
use App\Models\Subscription;
use App\Models\Plan;
use App\Models\InviteCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Notifications\CheckoutAccountCreated;

class AuthController extends Controller
{
    /**
     * Register new user & household
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'household_name' => 'required|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            // Create household first
            $household = Household::create([
                'name' => $validated['household_name'],
            ]);

            // Create user as owner
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'household_id' => $household->id,
                'role' => 'owner',
                'is_billing_owner' => true,
                'active' => true,
            ]);

            // Update household creator
            $household->update(['created_by' => $user->id]);

            // Get free plan
            $freePlan = Plan::where('slug', 'premium-free')->first();

            // Create free subscription
            $subscription = Subscription::create([
                'household_id' => $household->id,
                'plan_id' => $freePlan->id,
                'status' => 'active',
                'started_at' => now(),
                'expires_at' => null,
                'auto_renew' => false,
            ]);

            // Update household's current subscription
            $household->update(['current_subscription_id' => $subscription->id]);

            // Generate token
            $token = $user->createToken('auth_token')->plainTextToken;

            DB::commit();

            // Verifikasi menyusul: kegagalan kirim email tidak membatalkan pendaftaran
            $verificationSent = true;
            try {
                $user->sendEmailVerificationNotification();
            }
            catch (\Throwable $e) {
                $verificationSent = false;
                Log::warning('Verification email failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Registration successful',
                'user' => $user->load('household.currentSubscription.plan'),
                'token' => $token,
                'email_verification_sent' => $verificationSent,
                // Verifikasi menyusul, tidak ada fitur yang dikunci
                'requires_verification' => [
                    'paid_subscription' => false,
                    'invite_members' => false,
                ],
            ], 201);

        }
        catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Registration failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Checkout tanpa daftar: buat akun dari nama + email saja. Password
     * sementara dikembalikan sekali untuk ditampilkan di layar dan juga dikirim
     * lewat email. Token dikembalikan supaya pengunjung bisa langsung lanjut
     * ke pembayaran dengan alur subscribe/payments biasa.
     */
    public function checkoutAccount(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'household_name' => 'nullable|string|max:255',
        ], [
            'email.unique' => 'Email ini sudah terdaftar. Silakan masuk untuk melanjutkan pembayaran.',
        ]);

        $password = Str::password(12, symbols: false);

        DB::beginTransaction();
        try {
            $household = Household::create([
                'name' => $validated['household_name'] ?? 'Keluarga ' . $validated['name'],
            ]);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($password),
                'household_id' => $household->id,
                'role' => 'owner',
                'is_billing_owner' => true,
                'active' => true,
            ]);

            $household->update(['created_by' => $user->id]);

            // Mulai dari paket gratis; paket berbayar aktif setelah pembayaran lunas
            $freePlan = Plan::where('slug', 'premium-free')->first();

            $subscription = Subscription::create([
                'household_id' => $household->id,
                'plan_id' => $freePlan->id,
                'status' => 'active',
                'started_at' => now(),
                'expires_at' => null,
                'auto_renew' => false,
            ]);

            $household->update(['current_subscription_id' => $subscription->id]);

            $token = $user->createToken('auth_token')->plainTextToken;

            DB::commit();
        }
        catch (\Exception $e) {
            DB::rollBack();
            Log::error('Checkout account failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Akun belum bisa dibuat. Coba lagi sebentar lagi.',
            ], 500);
        }

        // Email hanya salinan: password juga ditampilkan sekali di layar checkout,
        // jadi kegagalan kirim email tidak membatalkan akun.
        $emailed = true;
        try {
            $user->notify(new CheckoutAccountCreated($password));
        }
        catch (\Throwable $e) {
            $emailed = false;
            Log::warning('Checkout account email failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Akun dibuat.',
            'user' => $user->load('household.currentSubscription.plan'),
            'token' => $token,
            'password' => $password,
            'password_emailed' => $emailed,
        ], 201);
    }

    /**
     * Register via invite code
     */
    public function registerWithInvite(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'invite_code' => 'required|string|exists:invite_codes,code',
        ]);

        DB::beginTransaction();
        try {
            // Find invite code
            $inviteCode = InviteCode::where('code', $validated['invite_code'])
                ->with('household')
                ->first();

            if (!$inviteCode->isValid()) {
                return response()->json([
                    'message' => 'Kode undangan tidak valid atau sudah kadaluarsa',
                ], 400);
            }

            // Check user limit
            $household = $inviteCode->household;
            $subscription = $household->currentSubscription;
            $maxUsers = $subscription->plan->getFeature('max_users', 0);
            $currentUsers = $household->users()->count();

            if ($maxUsers !== -1 && $currentUsers >= $maxUsers) {
                return response()->json([
                    'message' => 'Grup sudah mencapai batas pengguna',
                ], 403);
            }

            // Create user as member
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'household_id' => $household->id,
                'role' => 'member',
                'is_billing_owner' => false,
                'active' => true,
            ]);

            // Mark invite code as used
            $inviteCode->markAsUsed($user->id);

            // Generate token
            $token = $user->createToken('auth_token')->plainTextToken;

            DB::commit();

            return response()->json([
                'message' => 'Pendaftaran berhasil',
                'user' => $user->load('household.currentSubscription.plan'),
                'token' => $token,
            ], 201);

        }
        catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Pendaftaran gagal',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Login
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah'],
            ]);
        }

        if (!$user->active) {
            return response()->json([
                'message' => 'Akun Anda tidak aktif',
            ], 403);
        }

        // Generate token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil',
            'user' => $user->load('household.currentSubscription.plan'),
            'token' => $token,
        ]);
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout berhasil',
        ]);
    }

    /**
     * Get current user
     */
    public function me(Request $request)
    {
        $user = $request->user()->load([
            'household.currentSubscription.plan',
            'household.users',
        ]);

        return response()->json([
            'user' => $user,
        ]);
    }

    /**
     * Update profile
     */
    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $request->user()->id,
        ]);

        $user = $request->user();
        $emailChanged = isset($validated['email']) && $validated['email'] !== $user->email;

        // Email baru harus diverifikasi ulang
        if ($emailChanged) {
            $validated['email_verified_at'] = null;
        }

        $user->update($validated);

        $verificationSent = false;
        if ($emailChanged) {
            try {
                $user->sendEmailVerificationNotification();
                $verificationSent = true;
            }
            catch (\Throwable $e) {
                Log::warning('Verification email failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Profil berhasil diperbarui',
            'user' => $user,
            'email_verification_sent' => $verificationSent,
        ]);
    }

    /**
     * Change password
     */
    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if (!Hash::check($validated['current_password'], $request->user()->password)) {
            return response()->json([
                'message' => 'Kata sandi saat ini salah',
            ], 400);
        }

        $request->user()->update([
            'password' => Hash::make($validated['new_password']),
        ]);

        return response()->json([
            'message' => 'Kata sandi berhasil diubah',
        ]);
    }
}