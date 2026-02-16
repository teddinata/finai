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

            // Send verification email (non-blocking)
            $user->sendEmailVerificationNotification();

            DB::commit();

            return response()->json([
                'message' => 'Registration successful',
                'user' => $user->load('household.currentSubscription.plan'),
                'token' => $token,
                'email_verification_sent' => true,
                'requires_verification' => [
                    'paid_subscription' => true,
                    'invite_members' => true,
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

        $request->user()->update($validated);

        return response()->json([
            'message' => 'Profil berhasil diperbarui',
            'user' => $request->user(),
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