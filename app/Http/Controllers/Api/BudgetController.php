<?php
// app/Http/Controllers/Api/BudgetController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetRule;
use App\Models\BudgetLimit;
use App\Models\ParentCategory;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Carbon\Carbon;

class BudgetController extends Controller
{
    /**
     * Get budget overview
     */
    public function overview(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2020',
        ]);

        $month = $validated['month'] ?? now()->month;
        $year = $validated['year'] ?? now()->year;
        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = Carbon::create($year, $month, 1)->endOfMonth();

        // Get active budget rule
        $budgetRule = BudgetRule::where('household_id', $household->id)
            ->where('is_active', true)
            ->first();

        if (!$budgetRule) {
            return response()->json([
                'message' => 'No active budget rule found',
                'has_budget' => false,
            ]);
        }

        // Total income (Calculate first so we can use it as the budget target)
        $totalIncome = Transaction::where('household_id', $household->id)
            ->where('type', 'income')
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->sum('total');

        // Total spending
        $totalSpent = Transaction::where('household_id', $household->id)
            ->where('type', 'expense')
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->sum('total');

        // Get parent categories with spending
        $parentCategories = ParentCategory::orderBy('sort_order')->get();
        // Dynamically compute allocations based on actual income
        $allocations = $budgetRule->getAllocationAmountsDynamic($totalIncome);

        $budgetData = $parentCategories->map(function ($parent) use ($household, $startDate, $endDate, $allocations) {
            $spending = $parent->getTotalSpending(
                $household->id,
                $startDate->toDateString(),
                $endDate->toDateString()
            );

            $allocation = $allocations[$parent->slug] ?? null;

            return [
            'id' => $parent->id,
            'name' => $parent->name,
            'slug' => $parent->slug,
            'icon' => $parent->icon,
            'color' => $parent->color,
            'allocated_percentage' => $allocation['percentage'] ?? 0,
            'allocated_amount' => $allocation['amount'] ?? 0,
            'formatted_allocated' => $allocation['formatted_amount'] ?? 'Rp 0',
            'spent_amount' => $spending,
            'formatted_spent' => 'Rp ' . number_format($spending, 0, ',', '.'),
            'remaining_amount' => ($allocation['amount'] ?? 0) - $spending,
            'formatted_remaining' => 'Rp ' . number_format((($allocation['amount'] ?? 0) - $spending), 0, ',', '.'),
            'usage_percentage' => $allocation && $allocation['amount'] > 0
            ? round(($spending / $allocation['amount']), 1)
            : 0,
            'is_exceeded' => $allocation && $spending > $allocation['amount'],
            ];
        });

        return response()->json([
            'has_budget' => true,
            'budget_rule' => [
                'id' => $budgetRule->id,
                'name' => $budgetRule->name,
                'monthly_income_target' => $totalIncome, // Mocked for frontend payload compatibility
                'formatted_income_target' => 'Rp ' . number_format($totalIncome, 0, ',', '.'),
                'allocations' => $budgetRule->allocations,
            ],
            'period' => [
                'month' => $month,
                'year' => $year,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ],
            'summary' => [
                'total_income' => $totalIncome,
                'formatted_total_income' => 'Rp ' . number_format($totalIncome, 0, ',', '.'),
                'total_spent' => $totalSpent,
                'formatted_total_spent' => 'Rp ' . number_format($totalSpent, 0, ',', '.'),
                'budget_target' => $totalIncome,
                'formatted_budget_target' => 'Rp ' . number_format($totalIncome, 0, ',', '.'),
                'remaining_budget' => $totalIncome - $totalSpent,
                'formatted_remaining' => 'Rp ' . number_format(
                ($totalIncome - $totalSpent), 0, ',', '.'
            ),
                'usage_percentage' => $totalIncome > 0
                ? round(($totalSpent / $totalIncome) * 100, 1)
                : 0,
            ],
            'categories' => $budgetData,
        ]);
    }

    /**
     * Create or update budget rule
     */
    public function createRule(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'monthly_income_target' => 'nullable|integer|min:0', // Optional now, since it's dynamic
            'allocations' => 'required|array',
            'allocations.needs' => 'required|integer|min:0|max:100',
            'allocations.wants' => 'required|integer|min:0|max:100',
            'allocations.savings' => 'required|integer|min:0|max:100',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Validate allocations sum to 100%
        $total = array_sum($validated['allocations']);
        if ($total !== 100) {
            return response()->json([
                'message' => 'Allocations must sum to 100%',
                'current_total' => $total,
            ], 400);
        }

        // Deactivate existing rules
        BudgetRule::where('household_id', $household->id)
            ->update(['is_active' => false]);

        // Create new rule
        $budgetRule = BudgetRule::create([
            'household_id' => $household->id,
            ...$validated,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Budget rule created successfully',
            'budget_rule' => $budgetRule,
        ], 201);
    }

    /**
     * Get predefined budget templates
     */
    public function templates()
    {
        $templates = [
            [
                'name' => '50/30/20 Rule',
                'description' => 'Metode populer: 50% kebutuhan, 30% keinginan, 20% tabungan',
                'allocations' => [
                    'needs' => 50,
                    'wants' => 30,
                    'savings' => 20,
                ],
            ],
            [
                'name' => '70/20/10 Rule',
                'description' => 'Untuk pendapatan terbatas: 70% kebutuhan, 20% tabungan, 10% keinginan',
                'allocations' => [
                    'needs' => 70,
                    'wants' => 10,
                    'savings' => 20,
                ],
            ],
            [
                'name' => '60/30/10 Rule',
                'description' => 'Balanced: 60% kebutuhan, 30% keinginan, 10% tabungan',
                'allocations' => [
                    'needs' => 60,
                    'wants' => 30,
                    'savings' => 10,
                ],
            ],
            [
                'name' => '80/20 Rule',
                'description' => 'Simple: 80% pengeluaran, 20% tabungan',
                'allocations' => [
                    'needs' => 60,
                    'wants' => 20,
                    'savings' => 20,
                ],
            ],
        ];

        return response()->json([
            'templates' => $templates,
        ]);
    }

    /**
     * Get category-level budget limits
     */
    public function categoryLimits(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2020',
        ]);

        $month = $validated['month'] ?? now()->month;
        $year = $validated['year'] ?? now()->year;
        $startDate = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $endDate = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $limits = BudgetLimit::where('household_id', $household->id)
            ->where('is_active', true)
            ->where('start_date', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
            $q->whereNull('end_date')
                ->orWhere('end_date', '>=', $startDate);
        })
            ->with(['category', 'parentCategory'])
            ->get()
            ->map(function ($limit) {
            return [
            'id' => $limit->id,
            'category' => $limit->category ? [
            'id' => $limit->category->id,
            'name' => $limit->category->name,
            'icon' => $limit->category->icon,
            'color' => $limit->category->color,
            ] : null,
            'parent_category' => $limit->parentCategory ? [
            'id' => $limit->parentCategory->id,
            'name' => $limit->parentCategory->name,
            ] : null,
            'limit_amount' => $limit->limit_amount,
            'formatted_limit' => $limit->getFormattedLimit(),
            'spent_amount' => $limit->getCurrentSpending(),
            'formatted_spent' => 'Rp ' . number_format($limit->getCurrentSpending(), 0, ',', '.'),
            'remaining' => $limit->getRemainingBudget(),
            'formatted_remaining' => 'Rp ' . number_format($limit->getRemainingBudget(), 0, ',', '.'),
            'usage_percentage' => $limit->getUsagePercentage(),
            'is_exceeded' => $limit->isExceeded(),
            'period_type' => $limit->period_type,
            ];
        });

        return response()->json([
            'limits' => $limits,
            'period' => [
                'month' => $month,
                'year' => $year,
            ],
        ]);
    }

    /**
     * Create category budget limit
     */
    public function createLimit(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'parent_category_id' => 'nullable|exists:parent_categories,id',
            'category_id' => 'nullable|exists:categories,id',
            'limit_amount' => 'required|integer|min:0',
            'period_type' => 'required|in:monthly,yearly',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        if (!$validated['parent_category_id'] && !$validated['category_id']) {
            return response()->json([
                'message' => 'Either parent_category_id or category_id must be provided',
            ], 400);
        }

        $limit = BudgetLimit::create([
            'household_id' => $household->id,
            ...$validated,
        ]);

        return response()->json([
            'message' => 'Budget limit created successfully',
            'limit' => $limit,
        ], 201);
    }
}