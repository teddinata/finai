<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiEmailVerificationRequest;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use App\Models\User;

class EmailVerificationController extends Controller
{
    /**
     * Send verification email
     */
    public function send(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified',
            ], 400);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Verification link sent to your email',
            'email' => $user->email,
        ]);
    }

    /**
     * Verify email via signed URL
     */
    public function verify(ApiEmailVerificationRequest $request)
    {
        $user = User::find($request->route('id'));

        if (!$user) {
            return redirect(config('app.frontend_url') . '/email-verified?status=error&message=user_not_found');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect(config('app.frontend_url') . '/email-verified?status=already-verified');
        }

        $request->fulfill();

        return redirect(config('app.frontend_url') . '/email-verified?status=success');
    }

    /**
     * Verify email via API (alternative method with token)
     */
    public function verifyWithToken(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|exists:users,id',
            'hash' => 'required|string',
        ]);

        $user = User::find($validated['id']);

        if (!$user) {
            return response()->json([
                'message' => 'User not found',
            ], 404);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified',
            ], 400);
        }

        // Verify hash
        if (!hash_equals(
            (string) $validated['hash'],
            sha1($user->getEmailForVerification())
        )) {
            return response()->json([
                'message' => 'Invalid verification link',
            ], 403);
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return response()->json([
            'message' => 'Email verified successfully',
            'user' => $user,
        ]);
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
     * Resend verification email
     */
    public function resend(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified',
            ], 400);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Verification email resent successfully',
            'email' => $user->email,
        ]);
    }
}