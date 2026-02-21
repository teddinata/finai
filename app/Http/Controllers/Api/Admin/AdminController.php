<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Household;
use App\Models\Voucher; // Newly added
use App\Models\Transaction;
use App\Models\Subscription;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\UsageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminController extends Controller
{
    /**
     * Dashboard Overview
     */
    public function dashboard()
    {
        $today = now();
        $lastMonth = now()->subMonth();

        // User Statistics
        $totalUsers = User::count();
        $newUsersToday = User::whereDate('created_at', $today)->count();
        $newUsersThisMonth = User::whereMonth('created_at', $today->month)->count();
        $activeUsers = User::where('active', true)->count();

        // Household Statistics
        $totalHouseholds = Household::count();
        $newHouseholdsToday = Household::whereDate('created_at', $today)->count();

        // Subscription Statistics
        $activeSubscriptions = Subscription::where('status', 'active')->count();
        $trialSubscriptions = Subscription::where('status', 'trial')->count();
        $expiredSubscriptions = Subscription::where('status', 'expired')->count();

        // Revenue Statistics (this month)
        $monthlyRevenue = Payment::where('status', 'paid')
            ->whereMonth('paid_at', $today->month)
            ->sum('total');

        $todayRevenue = Payment::where('status', 'paid')
            ->whereDate('paid_at', $today)
            ->sum('total');

        // Transaction Statistics
        $totalTransactions = Transaction::count();
        $transactionsToday = Transaction::whereDate('created_at', $today)->count();
        $transactionsThisMonth = Transaction::whereMonth('created_at', $today->month)->count();

        // AI Scan Usage
        $aiScansToday = UsageLog::where('feature', 'ai_scan')
            ->whereDate('date', $today)
            ->sum('count');

        $aiScansThisMonth = UsageLog::where('feature', 'ai_scan')
            ->whereMonth('date', $today->month)
            ->sum('count');

        // Plan Distribution
        $planDistribution = Subscription::where('status', 'active')
            ->select('plan_id', DB::raw('count(*) as count'))
            ->with('plan:id,name,slug')
            ->groupBy('plan_id')
            ->get()
            ->map(fn($item) => [
        'plan' => $item->plan->name,
        'count' => $item->count,
        ]);

        // Recent Activity
        $recentUsers = User::latest()->take(5)->get();
        $recentPayments = Payment::where('status', 'paid')
            ->with(['user:id,name,email', 'subscription.plan:id,name'])
            ->latest('paid_at')
            ->take(5)
            ->get();

        return response()->json([
            'stats' => [
                'users' => [
                    'total' => $totalUsers,
                    'new_today' => $newUsersToday,
                    'new_this_month' => $newUsersThisMonth,
                    'active' => $activeUsers,
                ],
                'households' => [
                    'total' => $totalHouseholds,
                    'new_today' => $newHouseholdsToday,
                ],
                'subscriptions' => [
                    'active' => $activeSubscriptions,
                    'trial' => $trialSubscriptions,
                    'expired' => $expiredSubscriptions,
                ],
                'revenue' => [
                    'today' => $todayRevenue,
                    'formatted_today' => 'Rp ' . number_format($todayRevenue / 100, 0, ',', '.'),
                    'this_month' => $monthlyRevenue,
                    'formatted_month' => 'Rp ' . number_format($monthlyRevenue / 100, 0, ',', '.'),
                ],
                'transactions' => [
                    'total' => $totalTransactions,
                    'today' => $transactionsToday,
                    'this_month' => $transactionsThisMonth,
                ],
                'ai_scans' => [
                    'today' => $aiScansToday,
                    'this_month' => $aiScansThisMonth,
                ],
            ],
            'plan_distribution' => $planDistribution,
            'recent_users' => $recentUsers,
            'recent_payments' => $recentPayments,
        ]);
    }

    /**
     * List All Users (with filters & pagination)
     */
    public function users(Request $request)
    {
        $query = User::with(['household']);

        // Filters
        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        if ($request->has('active')) {
            $query->where('active', $request->active);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->latest()->paginate($request->get('per_page', 20));

        return response()->json($users);
    }

    /**
     * User Details
     */
    public function userDetail($userId)
    {
        $user = User::with([
            'household.currentSubscription.plan',
            'transactions' => fn($q) => $q->latest()->take(10),
            'payments' => fn($q) => $q->latest()->take(5),
        ])->findOrFail($userId);

        return response()->json(['user' => $user]);
    }

    /**
     * Update User (activate/deactivate/change role)
     */
    public function updateUser(Request $request, $userId)
    {
        $validated = $request->validate([
            'active' => 'sometimes|boolean',
            'role' => 'sometimes|in:admin,owner,member',
        ]);

        $user = User::findOrFail($userId);
        $user->update($validated);

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user,
        ]);
    }

    /**
     * List All Households
     */
    public function households(Request $request)
    {
        $query = Household::with([
            'users',
            'currentSubscription.plan',
        ])->withCount('users', 'transactions');

        if ($request->has('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        $households = $query->latest()->paginate($request->get('per_page', 20));

        return response()->json($households);
    }

    /**
     * Household Details
     */
    public function householdDetail($householdId)
    {
        $household = Household::with([
            'users',
            'currentSubscription.plan',
            'transactions' => fn($q) => $q->latest()->take(20),
            'subscriptions' => fn($q) => $q->latest()->take(5),
        ])->withCount('transactions', 'users')
            ->findOrFail($householdId);

        return response()->json(['household' => $household]);
    }

    /**
     * List All Subscriptions
     */
    public function subscriptions(Request $request)
    {
        $query = Subscription::with([
            'household',
            'plan',
        ]);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('plan_id')) {
            $query->where('plan_id', $request->plan_id);
        }

        $subscriptions = $query->latest()->paginate($request->get('per_page', 20));

        return response()->json($subscriptions);
    }

    /**
     * Cancel Subscription (Admin Override)
     */
    public function cancelSubscription(Request $request, $subscriptionId)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $subscription = Subscription::findOrFail($subscriptionId);
        $subscription->cancel($validated['reason']);

        return response()->json([
            'message' => 'Subscription cancelled successfully',
            'subscription' => $subscription,
        ]);
    }

    /**
     * List All Payments
     */
    public function payments(Request $request)
    {
        $query = Payment::with([
            'user',
            'household',
            'subscription.plan',
        ]);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $payments = $query->latest()->paginate($request->get('per_page', 20));

        return response()->json($payments);
    }

    /**
     * Revenue Analytics
     */
    public function revenue(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'group_by' => 'nullable|in:day,week,month',
        ]);

        $startDate = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate = $validated['end_date'] ?? now()->endOfMonth()->toDateString();
        $groupBy = $validated['group_by'] ?? 'day';

        // Base query for the selected date range
        $baseQuery = Payment::whereBetween('payments.created_at', [
            Carbon::parse($startDate)->startOfDay(),
            Carbon::parse($endDate)->endOfDay()
        ]);

        // 1. Overview Stats
        $totalPayments = (clone $baseQuery)->count();
        $paidPayments = (clone $baseQuery)->where('status', 'paid');
        $totalRevenue = $paidPayments->sum('total');
        $paidCount = $paidPayments->count();

        $avgPayment = $paidCount > 0 ? $totalRevenue / $paidCount : 0;
        $successRate = $totalPayments > 0 ? ($paidCount / $totalPayments) * 100 : 0;

        // 2. Revenue over time (Chart Data)
        $dateFormat = match ($groupBy) {
                'day' => '%Y-%m-%d',
                'week' => '%Y-%u',
                'month' => '%Y-%m',
            };

        $revenueOverTime = Payment::where('status', 'paid')
            ->whereBetween('paid_at', [
            Carbon::parse($startDate)->startOfDay(),
            Carbon::parse($endDate)->endOfDay()
        ])
            ->select(
            DB::raw("DATE_FORMAT(paid_at, '{$dateFormat}') as period"),
            DB::raw('SUM(total) as total'),
            DB::raw('COUNT(*) as count')
        )
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        // 3. Payment Status Breakdown
        $paymentStatus = (clone $baseQuery)
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total) as total'))
            ->groupBy('status')
            ->get();

        // 4. Top Plans Breakdown
        $topPlans = (clone $baseQuery)
            ->where('payments.status', 'paid')
            ->join('subscriptions', 'payments.subscription_id', '=', 'subscriptions.id')
            ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->select('plans.name as plan_name', DB::raw('COUNT(payments.id) as count'), DB::raw('SUM(payments.total) as total'))
            ->groupBy('plans.name')
            ->orderByDesc('total')
            ->take(5)
            ->get();

        return response()->json([
            'total_payments' => $totalPayments,
            'total_revenue' => $totalRevenue, // Frontend expects this key
            'formatted_total' => 'Rp ' . number_format($totalRevenue / 100, 0, ',', '.'),
            'avg_payment' => $avgPayment,
            'success_rate' => round($successRate, 1),
            'revenue' => $revenueOverTime, // For chart
            'payment_status' => $paymentStatus,
            'top_plans' => $topPlans,
        ]);
    }

    /**
     * Update Payment Status Manually
     */
    public function updatePayment(Request $request, $paymentId)
    {
        $validated = $request->validate([
            'status' => 'required|in:paid,failed,expired,pending',
        ]);

        $payment = Payment::with('subscription.plan')->findOrFail($paymentId);
        $oldStatus = $payment->status;
        $newStatus = $validated['status'];

        if ($oldStatus === $newStatus) {
            return response()->json(['message' => 'Status is already ' . $newStatus]);
        }

        if ($newStatus === 'paid') {
            // Use XenditService logic to ensure subscription activation
            // We mock the Xendit data structure
            $mockXenditData = [
                'id' => $payment->payment_gateway_id ?? 'manual-' . time(),
                'payment_channel' => 'MANUAL_ADMIN',
                'paid_amount' => $payment->total,
                'xendit_fee' => 0,
                'payment_id' => 'manual-' . time(),
            ];

            // Resolve service manually since we are in AdminController
            $xenditService = app(\App\Services\XenditService::class);
            $xenditService->handlePaymentSuccess($payment, $mockXenditData);
        }
        elseif ($newStatus === 'failed') {
            $payment->markAsFailed(['failure_reason' => 'Admin manual update']);
            if ($payment->subscription) {
                $payment->subscription->update(['status' => 'expired']);
            }
        }
        else {
            $payment->update(['status' => $newStatus]);
        }

        return response()->json([
            'message' => 'Payment status updated successfully',
            'payment' => $payment->fresh(),
        ]);
    }

    /**
     * Update Subscription Status Manually
     */
    public function updateSubscription(Request $request, $subscriptionId)
    {
        $validated = $request->validate([
            'status' => 'required|in:active,expired,pending,canceled,trial',
            'expires_at' => 'nullable|date',
        ]);

        $subscription = Subscription::with('plan')->findOrFail($subscriptionId);

        $updateData = ['status' => $validated['status']];
        if (isset($validated['expires_at'])) {
            $updateData['expires_at'] = $validated['expires_at'];
        }

        // If activating, ensure started_at is set
        if ($validated['status'] === 'active' && !$subscription->started_at) {
            $updateData['started_at'] = now();
        }

        $subscription->update($updateData);

        // If active, ensure household points to this
        if ($validated['status'] === 'active') {
            $subscription->household->update([
                'current_subscription_id' => $subscription->id
            ]);
        }

        return response()->json([
            'message' => 'Subscription updated successfully',
            'subscription' => $subscription->fresh(),
        ]);
    }

    /**
     * System Statistics
     */
    public function systemStats()
    {
        // Usage by feature
        $usageByFeature = UsageLog::select('feature', DB::raw('SUM(count) as total'))
            ->whereMonth('date', now()->month)
            ->groupBy('feature')
            ->get();

        // Most active households
        $activeHouseholds = Household::withCount([
            'transactions' => fn($q) => $q->whereMonth('created_at', now()->month)
        ])->orderByDesc('transactions_count')
            ->take(10)
            ->get();

        // Churn rate (cancelled subscriptions this month)
        $totalActiveLastMonth = Subscription::where('status', 'active')
            ->whereMonth('started_at', '<', now()->month)
            ->count();

        $cancelledThisMonth = Subscription::where('status', 'canceled')
            ->whereMonth('canceled_at', now()->month)
            ->count();

        $churnRate = $totalActiveLastMonth > 0
            ? ($cancelledThisMonth / $totalActiveLastMonth) * 100
            : 0;

        return response()->json([
            'usage_by_feature' => $usageByFeature,
            'active_households' => $activeHouseholds,
            'churn_rate' => round($churnRate, 2),
        ]);
    }

    /**
     * Plans Management
     */
    public function plans()
    {
        $plans = Plan::withCount([
            'subscriptions as active_subscriptions' => fn($q) => $q->where('status', 'active')
        ])->get();

        return response()->json(['plans' => $plans]);
    }

    /**
     * Update Plan
     */
    public function updatePlan(Request $request, $planId)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|unique:plans,slug,' . $planId,
            'type' => 'sometimes|string|max:50',
            'price' => 'sometimes|integer|min:0',
            'discount_price' => 'nullable|integer|min:0',
            'price_yearly' => 'nullable|integer|min:0',
            'discount_price_yearly' => 'nullable|integer|min:0',
            'currency' => 'sometimes|string|max:10',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'is_popular' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
            'features' => 'sometimes|array',
        ]);

        $plan = Plan::findOrFail($planId);

        // Merge features if provided
        if (isset($validated['features'])) {
            $existingFeatures = $plan->features ?? [];
            $validated['features'] = array_merge($existingFeatures, $validated['features']);
        }

        $plan->update($validated);

        return response()->json([
            'message' => 'Plan updated successfully',
            'plan' => $plan,
        ]);
    }
    /**
     * Vouchers Management
     */
    public function vouchers(Request $request)
    {
        $query = Voucher::withCount('usages');

        if ($request->has('search')) {
            $query->where('code', 'like', "%{$request->search}%")
                ->orWhere('name', 'like', "%{$request->search}%");
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->active);
        }

        $vouchers = $query->latest()->paginate($request->get('per_page', 20));

        return response()->json($vouchers);
    }

    public function storeVoucher(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:vouchers,code',
            'name' => 'required|string|max:255',
            'type' => 'required|in:percentage,fixed',
            'value' => 'required|integer|min:0',
            'max_discount_amount' => 'nullable|integer|min:0',
            'min_purchase_amount' => 'nullable|integer|min:0',
            'max_uses' => 'nullable|integer|min:1',
            'max_uses_per_household' => 'nullable|integer|min:1',
            'valid_from' => 'required|date',
            'valid_until' => 'nullable|date|after:valid_from',
            'applicable_plans' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $validated['created_by'] = $request->user()->id;

        $voucher = Voucher::create($validated);

        return response()->json([
            'message' => 'Voucher created successfully',
            'voucher' => $voucher,
        ]);
    }

    public function updateVoucher(Request $request, $id)
    {
        $voucher = Voucher::findOrFail($id);

        $validated = $request->validate([
            'code' => 'sometimes|string|unique:vouchers,code,' . $id,
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:percentage,fixed',
            'value' => 'sometimes|integer|min:0',
            'max_discount_amount' => 'nullable|integer|min:0',
            'min_purchase_amount' => 'nullable|integer|min:0',
            'max_uses' => 'nullable|integer|min:1',
            'max_uses_per_household' => 'nullable|integer|min:1',
            'valid_from' => 'sometimes|date',
            'valid_until' => 'nullable|date|after:valid_from',
            'applicable_plans' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $voucher->update($validated);

        return response()->json([
            'message' => 'Voucher updated successfully',
            'voucher' => $voucher,
        ]);
    }

    public function deleteVoucher($id)
    {
        $voucher = Voucher::findOrFail($id);

        if ($voucher->usages()->exists()) {
            // Soft delete or just deactivate? 
            // Better to just deactivate if used
            $voucher->update(['is_active' => false]);
            return response()->json(['message' => 'Voucher deactivated (has usage history)']);
        }

        $voucher->delete();

        return response()->json(['message' => 'Voucher deleted successfully']);
    }
}