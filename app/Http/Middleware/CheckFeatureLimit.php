<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\UsageLog;

class CheckFeatureLimit
{
    /**
     * Handle an incoming request.
     *
     * @param string $feature Feature name to check (transaction, ai_scan, storage)
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();
        $household = $user->household;

        if (!$household) {
            return response()->json([
                'success' => false,
                'message' => 'No household found',
            ], 403);
        }

        $subscription = $household->currentSubscription;

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'Akses terbatas. Silakan berlangganan untuk menggunakan fitur ini.',
                'action' => 'subscribe',
            ], 402);
        }

        // ✅ FIXED: Only enforce limits on active subscriptions
        // Allow canceled/expired to view data (read-only)
        if (!$subscription->isActive()) {
            // Block only write operations
            if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Subscription tidak aktif. Perpanjang untuk menambah data baru.',
                    'status' => $subscription->status,
                    'action' => $subscription->status === 'expired' ? 'renew' : 'reactivate',
                ], 402);
            }

            // Allow GET requests (viewing data)
            return $next($request);
        }

        $plan = $subscription->plan;

        // Proper feature key mapping
        $featureKey = match ($feature) {
                'transaction' => 'max_transactions_per_month',
                'ai_scan' => 'max_ai_scans_per_month',
                'storage' => 'storage_mb',
                default => "max_{$feature}s_per_month",
            };

        $limit = $plan->getFeature($featureKey, 0);

        // Unlimited (-1)
        if ($limit === -1) {
            return $next($request);
        }

        // Check current usage
        $currentUsage = UsageLog::getMonthlyUsage($household->id, $feature);

        if ($currentUsage >= $limit) {
            return response()->json([
                'success' => false,
                'message' => ucfirst($feature) . " limit reached for this month",
                'current_usage' => $currentUsage,
                'limit' => $limit,
                'remaining' => 0,
                'upgrade_message' => $household->getUpgradeSuggestion(),
                'action' => 'upgrade_plan',
            ], 429); // Too Many Requests
        }

        return $next($request);
    }
}