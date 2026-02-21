<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SavingsGoalController extends Controller
{
    /**
     * Get all savings goals
     */
    public function index(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'status' => 'nullable|in:active,completed,archived',
            'priority' => 'nullable|in:low,medium,high',
        ]);

        $query = SavingsGoal::forHousehold($household->id)
            ->with('creator:id,name');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['priority'])) {
            $query->where('priority', $validated['priority']);
        }

        $goals = $query->orderBy('priority', 'desc')
                      ->orderBy('deadline', 'asc')
                      ->get()
                      ->map(function ($goal) {
                          return $this->formatGoalResponse($goal);
                      });

        // Summary
        $summary = [
            'total_goals' => $goals->count(),
            'active_goals' => $goals->where('status', 'active')->count(),
            'completed_goals' => $goals->where('status', 'completed')->count(),
            'total_target' => $goals->sum('target_amount'),
            'total_saved' => $goals->sum('current_amount'),
            'total_remaining' => $goals->sum(fn($g) => $g['remaining_amount']),
        ];

        return response()->json([
            'goals' => $goals,
            'summary' => $summary,
        ]);
    }

    /**
     * Get single savings goal
     */
    public function show(Request $request, SavingsGoal $savingsGoal)
    {
        if ($savingsGoal->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $savingsGoal->load(['creator:id,name', 'transactions']);

        return response()->json([
            'goal' => $this->formatGoalResponse($savingsGoal, true),
        ]);
    }

    /**
     * Create savings goal
     */
    public function store(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'target_amount' => 'required|integer|min:10000', // Min Rp 100
            'deadline' => 'nullable|date|after:today',
            'icon' => 'nullable|string|max:10',
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'priority' => 'nullable|in:low,medium,high',
        ]);

        $goal = SavingsGoal::create([
            'household_id' => $household->id,
            'created_by' => $request->user()->id,
            ...$validated,
            'icon' => $validated['icon'] ?? '🎯',
            'color' => $validated['color'] ?? '#10B981',
            'priority' => $validated['priority'] ?? 'medium',
        ]);

        return response()->json([
            'message' => 'Savings goal created successfully',
            'goal' => $this->formatGoalResponse($goal),
        ], 201);
    }

    /**
     * Update savings goal
     */
    public function update(Request $request, SavingsGoal $savingsGoal)
    {
        if ($savingsGoal->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'target_amount' => 'sometimes|integer|min:10000',
            'deadline' => 'nullable|date|after:today',
            'icon' => 'sometimes|string|max:10',
            'color' => 'sometimes|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'priority' => 'sometimes|in:low,medium,high',
            'status' => 'sometimes|in:active,completed,archived',
        ]);

        $savingsGoal->update($validated);

        return response()->json([
            'message' => 'Savings goal updated successfully',
            'goal' => $this->formatGoalResponse($savingsGoal->fresh()),
        ]);
    }

    /**
     * Delete savings goal
     */
    public function destroy(Request $request, SavingsGoal $savingsGoal)
    {
        if ($savingsGoal->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check if has contributions
        if ($savingsGoal->transactions()->exists()) {
            return response()->json([
                'message' => 'Cannot delete goal with existing contributions. Archive it instead.',
            ], 400);
        }

        $savingsGoal->delete();

        return response()->json([
            'message' => 'Savings goal deleted successfully',
        ]);
    }

    /**
     * Add contribution to goal
     */
    public function addContribution(Request $request, SavingsGoal $savingsGoal)
    {
        if ($savingsGoal->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'transaction_id' => 'nullable|exists:transactions,id',
            'account_id' => 'required_without:transaction_id|exists:accounts,id',
            'amount' => 'required|integer|min:1',
            'notes' => 'nullable|string',
            'tanggal' => 'nullable|date',
        ]);

        DB::beginTransaction();
        try {
            if (!empty($validated['transaction_id'])) {
                $transaction = Transaction::find($validated['transaction_id']);
                
                // Verify transaction belongs to household
                if ($transaction->household_id !== $request->user()->household_id) {
                    throw new \Exception('Unauthorized transaction');
                }
            } else {
                // Find Tabungan category or fallback
                $category = \App\Models\Category::where('household_id', $savingsGoal->household_id)
                    ->where('name', 'like', '%Tabung%')
                    ->first();
                if (!$category) {
                    $category = \App\Models\Category::whereNull('household_id')
                        ->where('name', 'like', '%Tabung%')
                        ->first();
                }

                $transaction = Transaction::create([
                    'household_id' => $savingsGoal->household_id,
                    'created_by' => $request->user()->id,
                    'type' => 'expense',
                    'category_id' => $category ? $category->id : \App\Models\Category::first()->id,
                    'account_id' => $validated['account_id'],
                    'merchant' => 'Nabung ' . $savingsGoal->name,
                    'tanggal' => $validated['tanggal'] ?? now()->toDateString(),
                    'subtotal' => $validated['amount'],
                    'diskon' => 0,
                    'total' => $validated['amount'],
                    'source' => 'manual',
                    'notes' => $validated['notes'] ?? 'Auto-generated contribution',
                ]);
            }

            // Check if already linked
            if ($savingsGoal->transactions()->where('transaction_id', $transaction->id)->exists()) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Transaction already linked to this goal',
                ], 400);
            }

            $savingsGoal->addContribution($transaction, $validated['amount']);
            DB::commit();

            return response()->json([
                'message' => 'Contribution added successfully',
                'goal' => $this->formatGoalResponse($savingsGoal->fresh()),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to add contribution',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove contribution from goal
     */
    public function removeContribution(Request $request, SavingsGoal $savingsGoal, Transaction $transaction)
    {
        if ($savingsGoal->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $savingsGoal->removeContribution($transaction);
            DB::commit();

            return response()->json([
                'message' => 'Contribution removed successfully',
                'goal' => $this->formatGoalResponse($savingsGoal->fresh()),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to remove contribution',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Recalculate goal amount
     */
    public function recalculate(Request $request, SavingsGoal $savingsGoal)
    {
        if ($savingsGoal->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $savingsGoal->recalculateAmount();

        return response()->json([
            'message' => 'Goal recalculated successfully',
            'goal' => $this->formatGoalResponse($savingsGoal->fresh()),
        ]);
    }

    /**
     * Format goal response
     */
    private function formatGoalResponse(SavingsGoal $goal, bool $includeTransactions = false): array
    {
        $data = [
            'id' => $goal->id,
            'name' => $goal->name,
            'description' => $goal->description,
            'target_amount' => $goal->target_amount,
            'formatted_target' => $goal->getFormattedTarget(),
            'current_amount' => $goal->current_amount,
            'formatted_current' => $goal->getFormattedCurrent(),
            'remaining_amount' => $goal->getRemainingAmount(),
            'formatted_remaining' => $goal->getFormattedRemaining(),
            'progress_percentage' => $goal->getProgressPercentage(),
            'deadline' => $goal->deadline?->format('Y-m-d'),
            'days_remaining' => $goal->getDaysRemaining(),
            'icon' => $goal->icon,
            'color' => $goal->color,
            'status' => $goal->status,
            'priority' => $goal->priority,
            'is_completed' => $goal->isCompleted(),
            'is_overdue' => $goal->isOverdue(),
            'creator' => [
                'id' => $goal->creator->id,
                'name' => $goal->creator->name,
            ],
            'created_at' => $goal->created_at,
            'updated_at' => $goal->updated_at,
        ];

        if ($includeTransactions) {
            $data['contributions'] = $goal->transactions->map(function ($transaction) {
                return [
                    'transaction_id' => $transaction->id,
                    'merchant' => $transaction->merchant,
                    'tanggal' => $transaction->tanggal->format('Y-m-d'),
                    'amount' => $transaction->pivot->amount,
                    'formatted_amount' => 'Rp ' . number_format($transaction->pivot->amount, 0, ',', '.'),
                ];
            });
            $data['total_contributions'] = $goal->transactions->count();
        }

        return $data;
    }
}