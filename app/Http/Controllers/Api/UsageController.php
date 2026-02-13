<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UsageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UsageController extends Controller
{
    /**
     * Get current usage stats
     */
    public function index(Request $request)
    {
        $household = $request->user()->household;
        $subscription = $household->currentSubscription;

        if (!$subscription) {
            return response()->json([
                'message' => 'No active subscription',
            ], 404);
        }

        $plan = $subscription->plan;
        $features = $plan->features;

        // Get current month usage
        $currentMonth = now()->format('Y-m');
        
        $transactionUsage = UsageLog::getMonthlyUsage($household->id, 'transaction');
        $aiScanUsage = UsageLog::getMonthlyUsage($household->id, 'ai_scan');
        
        // Storage calculation (sum of all receipt images)
        $storageUsed = DB::table('transactions')
            ->where('household_id', $household->id)
            ->whereNotNull('receipt_image')
            ->count() * 500; // Approximate 500KB per image, convert to KB

        $storageUsedMB = round($storageUsed / 1024, 2);

        // User count
        $userCount = $household->users()->count();

        return response()->json([
            'subscription' => [
                'plan_name' => $plan->name,
                'plan_type' => $plan->type,
                'status' => $subscription->status,
                'expires_at' => $subscription->expires_at,
                'days_remaining' => $subscription->daysUntilExpiry(),
            ],
            'usage' => [
                'transactions' => [
                    'used' => $transactionUsage,
                    'limit' => $features['max_transactions_per_month'] ?? 0,
                    'unlimited' => ($features['max_transactions_per_month'] ?? 0) === -1,
                    'percentage' => $this->calculatePercentage($transactionUsage, $features['max_transactions_per_month'] ?? 0),
                    'remaining' => $this->calculateRemaining($transactionUsage, $features['max_transactions_per_month'] ?? 0),
                ],
                'ai_scans' => [
                    'used' => $aiScanUsage,
                    'limit' => $features['max_ai_scans_per_month'] ?? 0,
                    'unlimited' => ($features['max_ai_scans_per_month'] ?? 0) === -1,
                    'percentage' => $this->calculatePercentage($aiScanUsage, $features['max_ai_scans_per_month'] ?? 0),
                    'remaining' => $this->calculateRemaining($aiScanUsage, $features['max_ai_scans_per_month'] ?? 0),
                ],
                'storage' => [
                    'used_mb' => $storageUsedMB,
                    'limit_mb' => $features['storage_mb'] ?? 0,
                    'percentage' => $this->calculatePercentage($storageUsedMB, $features['storage_mb'] ?? 0),
                    'remaining_mb' => max(0, ($features['storage_mb'] ?? 0) - $storageUsedMB),
                ],
                'users' => [
                    'used' => $userCount,
                    'limit' => $features['max_users'] ?? 0,
                    'unlimited' => ($features['max_users'] ?? 0) === -1,
                    'percentage' => $this->calculatePercentage($userCount, $features['max_users'] ?? 0),
                    'remaining' => $this->calculateRemaining($userCount, $features['max_users'] ?? 0),
                ],
            ],
            'period' => [
                'current_month' => $currentMonth,
                'resets_at' => now()->endOfMonth()->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * Get usage history
     */
    public function history(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'feature' => 'nullable|in:transaction,ai_scan,storage',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $query = UsageLog::where('household_id', $household->id);

        if (isset($validated['feature'])) {
            $query->where('feature', $validated['feature']);
        }

        if (isset($validated['start_date'])) {
            $query->where('date', '>=', $validated['start_date']);
        }

        if (isset($validated['end_date'])) {
            $query->where('date', '<=', $validated['end_date']);
        }

        $logs = $query->orderBy('date', 'desc')
                     ->with('user:id,name')
                     ->paginate(30);

        return response()->json([
            'usage_history' => $logs->through(function ($log) {
                return [
                    'id' => $log->id,
                    'feature' => $log->feature,
                    'count' => $log->count,
                    'date' => $log->date->format('Y-m-d'),
                    'user' => $log->user ? [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                    ] : null,
                ];
            }),
        ]);
    }

    /**
     * Get daily usage breakdown for current month
     */
    public function daily(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'feature' => 'required|in:transaction,ai_scan',
            'month' => 'nullable|date_format:Y-m',
        ]);

        $month = $validated['month'] ?? now()->format('Y-m');
        $startOfMonth = \Carbon\Carbon::parse($month . '-01')->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $logs = UsageLog::where('household_id', $household->id)
            ->where('feature', $validated['feature'])
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->orderBy('date')
            ->get()
            ->map(function ($log) {
                return [
                    'date' => $log->date->format('Y-m-d'),
                    'day' => $log->date->format('d'),
                    'count' => $log->count,
                ];
            });

        return response()->json([
            'feature' => $validated['feature'],
            'month' => $month,
            'daily_usage' => $logs,
            'total' => $logs->sum('count'),
        ]);
    }

    /**
     * Check if feature can be used
     */
    public function canUse(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'feature' => 'required|in:transaction,ai_scan,storage',
        ]);

        $feature = $validated['feature'];
        $subscription = $household->currentSubscription;

        if (!$subscription || !$subscription->isActive()) {
            return response()->json([
                'can_use' => false,
                'reason' => 'No active subscription',
            ]);
        }

        $plan = $subscription->plan;
        $limit = $plan->getFeature("max_{$feature}s_per_month", 0);

        // Unlimited
        if ($limit === -1) {
            return response()->json([
                'can_use' => true,
                'unlimited' => true,
            ]);
        }

        // Check current usage
        $currentUsage = UsageLog::getMonthlyUsage($household->id, $feature);

        $canUse = $currentUsage < $limit;

        return response()->json([
            'can_use' => $canUse,
            'current_usage' => $currentUsage,
            'limit' => $limit,
            'remaining' => max(0, $limit - $currentUsage),
            'reason' => $canUse ? null : 'Monthly limit reached',
        ]);
    }

    /**
     * Calculate percentage
     */
    private function calculatePercentage(int $used, int $limit): float
    {
        if ($limit === -1) {
            return 0; // Unlimited
        }

        if ($limit === 0) {
            return 0;
        }

        return round(($used / $limit) , 1);
    }

    /**
     * Calculate remaining
     */
    private function calculateRemaining(int $used, int $limit): int
    {
        if ($limit === -1) {
            return -1; // Unlimited
        }

        return max(0, $limit - $used);
    }
}