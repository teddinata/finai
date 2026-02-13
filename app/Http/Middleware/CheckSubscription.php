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
                'message' => 'Unauthenticated',
            ], 401);
        }

        if (!$user->household) {
            return response()->json([
                'message' => 'No household found',
            ], 403);
        }

        $subscription = $user->household->currentSubscription;

        if (!$subscription) {
            return response()->json([
                'message' => 'No subscription found',
                'action' => 'subscribe',
            ], 402); // Payment Required
        }

        if ($subscription->isExpired()) {
            return response()->json([
                'message' => 'Subscription has expired',
                'action' => 'renew',
                'expired_at' => $subscription->expires_at,
            ], 402);
        }

        if ($subscription->isCanceled()) {
            return response()->json([
                'message' => 'Subscription has been canceled',
                'action' => 'reactivate',
                'canceled_at' => $subscription->canceled_at,
            ], 402);
        }

        return $next($request);
    }
}