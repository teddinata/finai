<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UsageLog;
use App\Models\Payment;
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

        // ✅ FIXED: Handle no subscription or canceled subscription
        if (!$subscription || in_array($subscription->status, ['canceled', 'expired'])) {
            // Get free plan
            $freePlan = \App\Models\Plan::where('slug', 'premium-free')->first();
            
            if (!$freePlan) {
                return response()->json([
                    'success' => false,
                    'message' => 'No plan available',
                ], 404);
            }

            // Get current usage
            $transactionUsage = UsageLog::getMonthlyUsage($household->id, 'transaction');
            $aiScanUsage = UsageLog::getMonthlyUsage($household->id, 'ai_scan');
            
            $storageUsed = DB::table('transactions')
                ->where('household_id', $household->id)
                ->whereNotNull('receipt_image')
                ->count() * 500;
            $storageUsedMB = round($storageUsed / 1024, 2);
            
            $userCount = $household->users()->count();

            return response()->json([
                'success' => true,
                'subscription' => [
                    'plan_name' => $freePlan->name,
                    'plan_slug' => $freePlan->slug,
                    'plan_type' => 'free',
                    'status' => 'active',
                    'expires_at' => null,
                    'days_remaining' => null,
                ],
                'usage' => $this->getUsageData($freePlan, $transactionUsage, $aiScanUsage, $storageUsedMB, $userCount),
                'period' => [
                    'current_month' => now()->format('Y-m'),
                    'resets_at' => now()->endOfMonth()->format('Y-m-d H:i:s'),
                ],
                'pending_upgrade' => null,
            ]);
        }

        $plan = $subscription->plan;
        
        // ✅ Check for pending upgrade payment
        $pendingPayment = Payment::where('household_id', $household->id)
            ->where('status', 'pending')
            ->whereHas('subscription', function ($query) use ($subscription) {
                $query->where('plan_id', '!=', $subscription->plan_id);
            })
            ->with('subscription.plan')
            ->latest()
            ->first();
        
        // Get current month usage
        $currentMonth = now()->format('Y-m');
        $transactionUsage = UsageLog::getMonthlyUsage($household->id, 'transaction');
        $aiScanUsage = UsageLog::getMonthlyUsage($household->id, 'ai_scan');
        
        $storageUsed = DB::table('transactions')
            ->where('household_id', $household->id)
            ->whereNotNull('receipt_image')
            ->count() * 500;
        $storageUsedMB = round($storageUsed / 1024, 2);
        
        $userCount = $household->users()->count();

        $response = [
            'success' => true,
            'subscription' => [
                'plan_name' => $plan->name,
                'plan_slug' => $plan->slug,
                'plan_type' => $plan->type,
                'status' => $subscription->status,
                'expires_at' => $subscription->expires_at,
                'days_remaining' => $subscription->daysUntilExpiry(),
            ],
            'usage' => $this->getUsageData($plan, $transactionUsage, $aiScanUsage, $storageUsedMB, $userCount),
            'period' => [
                'current_month' => $currentMonth,
                'resets_at' => now()->endOfMonth()->format('Y-m-d H:i:s'),
            ],
        ];

        // ✅ Add pending upgrade info if exists
        if ($pendingPayment) {
            $pendingPlan = $pendingPayment->subscription->plan;
            
            $response['pending_upgrade'] = [
                'payment_id' => $pendingPayment->id,
                'plan_name' => $pendingPlan->name,
                'plan_slug' => $pendingPlan->slug,
                'plan_type' => $pendingPlan->type,
                'amount' => $pendingPayment->total,
                'formatted_amount' => 'Rp ' . number_format($pendingPayment->total, 0, ',', '.'),
                'payment_method' => $pendingPayment->payment_method,
                'created_at' => $pendingPayment->created_at,
                'usage_preview' => $this->getUsageData($pendingPlan, $transactionUsage, $aiScanUsage, $storageUsedMB, $userCount),
            ];
        }

        return response()->json($response);
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
     * ✅ Helper to get usage data for a plan
     */
    private function getUsageData($plan, $transactionUsage, $aiScanUsage, $storageUsedMB, $userCount)
    {
        $features = $plan->features;

        return [
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
        ];
    }

    /**
     * Calculate percentage
     */
    private function calculatePercentage($used, $limit): float
    {
        if ($limit === -1) {
            return 0; // Unlimited
        }

        if ($limit === 0) {
            return 0;
        }

        return round(($used / $limit) * 100, 1);
    }

    /**
     * Calculate remaining
     */
    private function calculateRemaining($used, $limit): int
    {
        if ($limit === -1) {
            return -1; // Unlimited
        }

        return max(0, $limit - $used);
    }
}