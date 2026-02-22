<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    protected $aiService;

    public function __construct(\App\Services\AiAnalysisService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Analyze financial data with AI
     */
    public function analyze(Request $request)
    {
        set_time_limit(120); // Increase execution time to 2 minutes
        $household = $request->user()->household;

        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $startDate = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate = $validated['end_date'] ?? now()->endOfMonth()->toDateString();
        $periodLabel = Carbon::parse($startDate)->format('d M Y') . ' - ' . Carbon::parse($endDate)->format('d M Y');

        // Reuse summary logic to get data
        // We can manually call existing methods or duplicate the query logic for efficiency specific to AI context
        // reusing logic for consistency

        $totalIncome = Transaction::forHousehold($household->id)
            ->where('type', 'income')
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->sum('total');

        $totalExpense = Transaction::forHousehold($household->id)
            ->where('type', 'expense')
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->sum('total');

        $topCategories = Transaction::forHousehold($household->id)
            ->where('type', 'expense')
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->select('category_id', DB::raw('SUM(total) as total'))
            ->with('category:id,name')
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(function ($item) {
            return [
            'category' => $item->category->name,
            'total' => $item->total,
            ];
        });



        // Add Income Analysis
        $topIncomeSources = Transaction::forHousehold($household->id)
            ->where('type', 'income')
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->select('category_id', DB::raw('SUM(total) as total'))
            ->with('category:id,name')
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->limit(3)
            ->get()
            ->map(function ($item) {
            return [
            'source' => $item->category->name,
            'total' => $item->total,
            ];
        });

        // 1. Budgets (Active)
        $budgets = \App\Models\BudgetLimit::where('household_id', $household->id)
            ->where('is_active', true)
            ->with('category:id,name')
            ->get()
            ->map(function ($budget) {
            $spending = $budget->getCurrentSpending();
            return [
            'category' => $budget->category->name ?? 'Unknown',
            'limit' => $budget->limit_amount,
            'spent' => $spending,
            'remaining' => $budget->limit_amount - $spending,
            'status' => $spending > $budget->limit_amount ? 'Over Budget' : 'Safe',
            ];
        });

        // 2. Savings (Active)
        $savings = \App\Models\SavingsGoal::where('household_id', $household->id)
            ->where('status', 'active')
            ->get()
            ->map(function ($goal) {
            return [
            'name' => $goal->name,
            'target' => $goal->target_amount,
            'current' => $goal->current_amount,
            'progress' => $goal->getProgressPercentage() . '%',
            'remaining' => $goal->getRemainingAmount(),
            ];
        });

        // 3. Investments (Active)
        $investments = \App\Models\Investment::where('household_id', $household->id)
            ->where('status', 'active')
            ->get();

        $investmentSummary = [
            'total_value' => $investments->sum('current_value'),
            'total_invested' => $investments->sum('initial_amount'),
            'profit_loss' => $investments->sum('current_value') - $investments->sum('initial_amount'),
            'count' => $investments->count(),
        ];

        // 4. Recurring Transactions (Active Bills/Income)
        $recurring = \App\Models\RecurringTransaction::where('household_id', $household->id)
            ->where('status', 'active')
            ->get()
            ->map(function ($rec) {
            return [
            'name' => $rec->name,
            'amount' => $rec->amount,
            'type' => $rec->type, // expense/income
            'frequency' => $rec->frequency,
            'next_due' => $rec->next_occurrence ? $rec->next_occurrence->format('Y-m-d') : 'N/A',
            ];
        });

        // 5. Loans (Active)
        $loans = \App\Models\Loan::where('household_id', $household->id)
            ->where('status', 'active')
            ->get()
            ->map(function ($loan) {
            return [
            'name' => $loan->name,
            'total_amount' => $loan->total_amount,
            'paid_amount' => $loan->paid_amount,
            'remaining_amount' => $loan->total_amount - $loan->paid_amount,
            'installment' => $loan->installment_amount,
            'next_payment' => $loan->next_payment_date ?\Carbon\Carbon::parse($loan->next_payment_date)->format('Y-m-d') : 'N/A',
            ];
        });

        $data = [
            'period' => $periodLabel,
            'summary' => [
                'total_income' => $totalIncome,
                'total_expense' => $totalExpense,
                'net_income' => $totalIncome - $totalExpense,
            ],
            'expenses_breakdown' => $topCategories,
            'income_breakdown' => $topIncomeSources,
            'budgets' => $budgets,
            'savings_goals' => $savings,
            'investments' => $investmentSummary,
            'recurring_transactions' => $recurring,
            'loans' => $loans,
        ];

        $analysis = $this->aiService->analyze($data, $periodLabel);

        return response()->json([
            'analysis' => $analysis
        ]);
    }

    /**
     * Get dashboard summary
     */
    public function summary(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $startDate = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate = $validated['end_date'] ?? now()->endOfMonth()->toDateString();

        // Total income
        $totalIncome = Transaction::forHousehold($household->id)
            ->where('type', 'income')
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->sum('total');

        // Total expense
        $totalExpense = Transaction::forHousehold($household->id)
            ->where('type', 'expense')
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->sum('total');

        // Net income
        $netIncome = $totalIncome - $totalExpense;

        // Transaction count
        $transactionCount = Transaction::forHousehold($household->id)
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->count();

        // Average transaction
        $avgTransaction = $transactionCount > 0 ? $totalExpense / $transactionCount : 0;

        // Compared to previous period (expenses only)
        $periodDays = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
        $prevStartDate = Carbon::parse($startDate)->subDays($periodDays)->toDateString();
        $prevEndDate = Carbon::parse($startDate)->subDay()->toDateString();

        $prevTotalExpense = Transaction::forHousehold($household->id)
            ->where('type', 'expense')
            ->whereBetween('tanggal', [$prevStartDate, $prevEndDate])
            ->sum('total');

        $spendingChange = $prevTotalExpense > 0
            ? (($totalExpense - $prevTotalExpense) / $prevTotalExpense)
            : 0;

        // Top categories (expenses only)
        $topCategories = Transaction::forHousehold($household->id)
            ->where('type', 'expense')
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->select('category_id', DB::raw('SUM(total) as total'), DB::raw('COUNT(*) as count'))
            ->with('category:id,name,icon,color')
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(function ($item) use ($totalExpense) {
            return [
            'category' => [
            'id' => $item->category->id,
            'name' => $item->category->name,
            'icon' => $item->category->icon,
            'color' => $item->category->color,
            ],
            'total' => $item->total,
            'formatted_total' => 'Rp ' . number_format($item->total, 0, ',', '.'),
            'count' => $item->count,
            'percentage' => $totalExpense > 0 ? round(($item->total / $totalExpense), 1) : 0,
            ];
        });

        // Top merchants (expenses only)
        $topMerchants = Transaction::forHousehold($household->id)
            ->where('type', 'expense')
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->select('merchant', DB::raw('SUM(total) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('merchant')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(function ($item) use ($totalExpense) {
            return [
            'merchant' => $item->merchant,
            'total' => $item->total,
            'formatted_total' => 'Rp ' . number_format($item->total, 0, ',', '.'),
            'count' => $item->count,
            'percentage' => $totalExpense > 0 ? round(($item->total / $totalExpense), 1) : 0,
            ];
        });

        // Accounts breakdown (Current balances)
        $accountBreakdown = \App\Models\Account::where('household_id', $household->id)
            ->where('is_active', true)
            ->select('id', 'name', 'icon', 'color', 'current_balance')
            ->get()
            ->map(function ($account) {
            return [
            'account' => [
            'id' => $account->id,
            'name' => $account->name,
            'icon' => $account->icon,
            'color' => $account->color,
            ],
            'total' => $account->current_balance,
            'formatted_total' => 'Rp ' . number_format($account->current_balance, 0, ',', '.'),
            ];
        });


        // Payment method breakdown (all transactions)
        // $paymentMethods = Transaction::forHousehold($household->id)
        //     ->whereBetween('tanggal', [$startDate, $endDate])
        //     ->select('metode_pembayaran', DB::raw('SUM(total) as total'), DB::raw('COUNT(*) as count'))
        //     ->groupBy('metode_pembayaran')
        //     ->get()
        //     ->map(function ($item) {
        //         return [
        //             'metode_pembayaran' => $item->metode_pembayaran,
        //             'label' => $this->getPaymentMethodLabel($item->metode_pembayaran),
        //             'total' => $item->total,
        //             'formatted_total' => 'Rp ' . number_format($item->total , 0, ',', '.'),
        //             'count' => $item->count,
        //         ];
        //     });

        return response()->json([
            'summary' => [
                'total_income' => $totalIncome,
                'formatted_total_income' => 'Rp ' . number_format($totalIncome, 0, ',', '.'),
                'total_expense' => $totalExpense,
                'formatted_total_expense' => 'Rp ' . number_format($totalExpense, 0, ',', '.'),
                'total_spending' => $totalExpense, // Backward compatibility
                'formatted_total_spending' => 'Rp ' . number_format($totalExpense, 0, ',', '.'),
                'net_income' => $netIncome,
                'formatted_net_income' => 'Rp ' . number_format($netIncome, 0, ',', '.'),
                'transaction_count' => $transactionCount,
                'avg_transaction' => round($avgTransaction),
                'formatted_avg_transaction' => 'Rp ' . number_format($avgTransaction, 0, ',', '.'),
                'spending_change_percentage' => round($spendingChange, 1),
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
            ],
            'top_categories' => $topCategories,
            'top_merchants' => $topMerchants,
            'accounts' => $accountBreakdown,
            // 'payment_methods' => $paymentMethods,
        ]);
    }

    /**
     * Get spending by category
     */
    public function byCategory(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'type' => 'nullable|in:income,expense',
        ]);

        $startDate = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate = $validated['end_date'] ?? now()->endOfMonth()->toDateString();
        $type = $validated['type'] ?? 'expense';

        $query = Transaction::forHousehold($household->id)
            ->where('type', $type)
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->select('category_id', DB::raw('SUM(total) as total'), DB::raw('COUNT(*) as count'))
            ->with('category:id,name,icon,color')
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->orderByDesc('total');

        $categories = $query->get();
        $totalAmount = $categories->sum('total');

        $data = $categories->map(function ($item) use ($totalAmount) {
            return [
            'category_id' => $item->category->id,
            'name' => $item->category->name,
            'icon' => $item->category->icon,
            'color' => $item->category->color,
            'total' => $item->total,
            'formatted_total' => 'Rp ' . number_format($item->total, 0, ',', '.'),
            'count' => $item->count,
            'percentage' => $totalAmount > 0 ? round(($item->total / $totalAmount), 1) : 0,
            ];
        });

        return response()->json([
            'categories' => $data,
            'total' => $totalAmount,
            'formatted_total' => 'Rp ' . number_format($totalAmount, 0, ',', '.'),
        ]);
    }

    /**
     * Get spending by merchant
     */
    public function byMerchant(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'limit' => 'nullable|integer|min:5|max:50',
            'type' => 'nullable|in:income,expense',
        ]);

        $startDate = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate = $validated['end_date'] ?? now()->endOfMonth()->toDateString();
        $limit = $validated['limit'] ?? 20;
        $type = $validated['type'] ?? 'expense';

        $merchants = Transaction::forHousehold($household->id)
            ->where('type', $type)
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->select('merchant', DB::raw('SUM(total) as total'), DB::raw('COUNT(*) as transaction_count'), DB::raw('MAX(tanggal) as last_transaction'))
            ->groupBy('merchant')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        $totalAmount = Transaction::forHousehold($household->id)
            ->where('type', $type)
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->sum('total');

        $data = $merchants->map(function ($item) use ($totalAmount) {
            return [
            'merchant' => $item->merchant,
            'total' => $item->total,
            'formatted_total' => 'Rp ' . number_format($item->total, 0, ',', '.'),
            'transaction_count' => $item->transaction_count,
            'last_transaction' => $item->last_transaction,
            'percentage' => $totalAmount > 0 ? round(($item->total / $totalAmount), 1) : 0,
            ];
        });

        return response()->json([
            'merchants' => $data,
            'total' => $totalAmount,
            'formatted_total' => 'Rp ' . number_format($totalAmount, 0, ',', '.'),
        ]);
    }

    /**
     * Get spending timeline (daily/weekly/monthly)
     */
    public function timeline(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'group_by' => 'nullable|in:day,week,month',
            'type' => 'nullable|in:income,expense,both',
        ]);

        $startDate = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate = $validated['end_date'] ?? now()->endOfMonth()->toDateString();
        $groupBy = $validated['group_by'] ?? 'day';
        $type = $validated['type'] ?? 'expense';

        $dateFormat = match ($groupBy) {
                'day' => '%Y-%m-%d',
                'week' => '%Y-%u',
                'month' => '%Y-%m',
            };

        $query = Transaction::forHousehold($household->id)
            ->whereBetween('tanggal', [$startDate, $endDate]);

        if ($type !== 'both') {
            $query->where('type', $type);
        }

        $transactions = $query->select(
            DB::raw("DATE_FORMAT(tanggal, '{$dateFormat}') as period"),
            DB::raw('SUM(total) as total'),
            DB::raw('COUNT(*) as count')
        )
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(function ($item) use ($groupBy) {
            return [
            'period' => $item->period,
            'label' => $this->formatPeriodLabel($item->period, $groupBy),
            'total' => $item->total,
            'formatted_total' => 'Rp ' . number_format($item->total, 0, ',', '.'),
            'count' => $item->count,
            ];
        });

        return response()->json([
            'timeline' => $transactions,
            'group_by' => $groupBy,
        ]);
    }

    /**
     * Get comparison between periods
     */
    public function comparison(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'current_start' => 'required|date',
            'current_end' => 'required|date|after_or_equal:current_start',
            'previous_start' => 'required|date',
            'previous_end' => 'required|date|after_or_equal:previous_start',
        ]);

        // Current period
        $currentSpending = Transaction::forHousehold($household->id)
            ->where('type', 'expense')
            ->whereBetween('tanggal', [$validated['current_start'], $validated['current_end']])
            ->sum('total');

        $currentCount = Transaction::forHousehold($household->id)
            ->whereBetween('tanggal', [$validated['current_start'], $validated['current_end']])
            ->count();

        // Previous period
        $previousSpending = Transaction::forHousehold($household->id)
            ->where('type', 'expense')
            ->whereBetween('tanggal', [$validated['previous_start'], $validated['previous_end']])
            ->sum('total');

        $previousCount = Transaction::forHousehold($household->id)
            ->whereBetween('tanggal', [$validated['previous_start'], $validated['previous_end']])
            ->count();

        // Calculate changes
        $spendingChange = $previousSpending > 0
            ? (($currentSpending - $previousSpending) / $previousSpending)
            : 0;

        $countChange = $previousCount > 0
            ? (($currentCount - $previousCount) / $previousCount)
            : 0;

        return response()->json([
            'current_period' => [
                'start_date' => $validated['current_start'],
                'end_date' => $validated['current_end'],
                'total_spending' => $currentSpending,
                'formatted_total' => 'Rp ' . number_format($currentSpending, 0, ',', '.'),
                'transaction_count' => $currentCount,
            ],
            'previous_period' => [
                'start_date' => $validated['previous_start'],
                'end_date' => $validated['previous_end'],
                'total_spending' => $previousSpending,
                'formatted_total' => 'Rp ' . number_format($previousSpending, 0, ',', '.'),
                'transaction_count' => $previousCount,
            ],
            'changes' => [
                'spending_change_percentage' => round($spendingChange, 1),
                'spending_change_amount' => $currentSpending - $previousSpending,
                'count_change_percentage' => round($countChange, 1),
                'count_change_amount' => $currentCount - $previousCount,
            ],
        ]);
    }

    /**
     * Get monthly trends (last 12 months)
     */
    public function trends(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'months' => 'nullable|integer|min:3|max:24',
        ]);

        $months = $validated['months'] ?? 12;
        $startDate = now()->subMonths($months)->startOfMonth();

        $trends = Transaction::forHousehold($household->id)
            ->where('type', 'expense')
            ->where('tanggal', '>=', $startDate)
            ->select(
            DB::raw('YEAR(tanggal) as year'),
            DB::raw('MONTH(tanggal) as month'),
            DB::raw('SUM(total) as total'),
            DB::raw('COUNT(*) as count'),
            DB::raw('AVG(total) as avg')
        )
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->map(function ($item) {
            $date = Carbon::createFromDate($item->year, $item->month, 1);
            return [
            'period' => $date->format('Y-m'),
            'label' => $date->format('M Y'),
            'total' => $item->total,
            'formatted_total' => 'Rp ' . number_format($item->total, 0, ',', '.'),
            'count' => $item->count,
            'avg' => round($item->avg),
            'formatted_avg' => 'Rp ' . number_format($item->avg, 0, ',', '.'),
            ];
        });

        // Calculate trend direction
        $recentMonths = $trends->take(-3);
        $avgRecent = $recentMonths->avg('total') ?? 0;
        $previousMonths = $trends->slice(-6, 3);
        $avgPrevious = $previousMonths->avg('total') ?? 0;

        $trendDirection = $avgPrevious > 0
            ? (($avgRecent - $avgPrevious) / $avgPrevious)
            : 0;

        return response()->json([
            'trends' => $trends,
            'trend_analysis' => [
                'direction' => $trendDirection > 5 ? 'increasing' : ($trendDirection < -5 ? 'decreasing' : 'stable'),
                'change_percentage' => round($trendDirection, 1),
            ],
        ]);
    }

    /**
     * Get payment method label
     */
    // private function getPaymentMethodLabel(string $method): string
    // {
    //     return match($method) {
    //         'cash' => 'Tunai',
    //         'transfer' => 'Transfer Bank',
    //         'kartu_kredit' => 'Kartu Kredit',
    //         'kartu_debit' => 'Kartu Debit',
    //         'ewallet' => 'E-Wallet',
    //         'other' => 'Lainnya',
    //         default => $method,
    //     };
    // }

    /**
     * Format period label
     */
    private function formatPeriodLabel(string $period, string $groupBy): string
    {
        return match ($groupBy) {
                'day' => Carbon::parse($period)->format('d M Y'),
                'week' => 'Week ' . substr($period, -2) . ' ' . substr($period, 0, 4),
                'month' => Carbon::parse($period . '-01')->format('M Y'),
                default => $period,
            };
    }
}