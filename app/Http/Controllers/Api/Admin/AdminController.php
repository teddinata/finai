<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Household;
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
        $query = User::with(['household:id,name']);

        // Filters
        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        if ($request->has('active')) {
            $query->where('active', $request->active);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
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
            'users:id,household_id,name,email,role',
            'currentSubscription.plan:id,name,slug',
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
            'household:id,name',
            'plan:id,name,slug,price',
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
            'user:id,name,email',
            'household:id,name',
            'subscription.plan:id,name',
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

        $startDate = $validated['start_date'] ?? now()->startOfMonth();
        $endDate = $validated['end_date'] ?? now()->endOfMonth();
        $groupBy = $validated['group_by'] ?? 'day';

        $dateFormat = match($groupBy) {
            'day' => '%Y-%m-%d',
            'week' => '%Y-%u',
            'month' => '%Y-%m',
        };

        $revenue = Payment::where('status', 'paid')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->select(
                DB::raw("DATE_FORMAT(paid_at, '{$dateFormat}') as period"),
                DB::raw('SUM(total) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        $totalRevenue = $revenue->sum('total');

        return response()->json([
            'revenue' => $revenue,
            'total' => $totalRevenue,
            'formatted_total' => 'Rp ' . number_format($totalRevenue / 100, 0, ',', '.'),
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
            'is_active' => 'sometimes|boolean',
            'price' => 'sometimes|integer|min:0',
            'features' => 'sometimes|array',
        ]);

        $plan = Plan::findOrFail($planId);
        $plan->update($validated);

        return response()->json([
            'message' => 'Plan updated successfully',
            'plan' => $plan,
        ]);
    }
}