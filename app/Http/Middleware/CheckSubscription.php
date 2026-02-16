<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscription
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        if (!$user->household) {
            return response()->json([
                'success' => false,
                'message' => 'No household found',
            ], 403);
        }

        $subscription = $user->household->currentSubscription;

        // ✅ FIXED: Allow canceled/expired subscriptions to pass through
        // Only block if truly no subscription exists
        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No subscription found',
                'action' => 'subscribe',
            ], 402);
        }

        // ✅ REMOVED: Don't block expired/canceled
        // Let controller handle these cases
        // This allows users to:
        // - View their data
        // - See subscription status
        // - Resubscribe/renew

        return $next($request);
    }
}