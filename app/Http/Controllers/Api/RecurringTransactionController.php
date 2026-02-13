<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RecurringTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecurringTransactionController extends Controller
{
    /**
     * Get all recurring transactions
     */
    public function index(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'status' => 'nullable|in:active,paused,completed,cancelled',
            'type' => 'nullable|in:income,expense',
            'frequency' => 'nullable|in:daily,weekly,monthly,yearly',
        ]);

        $query = RecurringTransaction::forHousehold($household->id)
            ->with(['category:id,name,icon,color', 'account:id,name', 'creator:id,name']);

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (isset($validated['frequency'])) {
            $query->where('frequency', $validated['frequency']);
        }

        $recurring = $query->orderBy('next_occurrence', 'asc')
                          ->get()
                          ->map(function ($r) {
                              return $this->formatRecurringResponse($r);
                          });

        return response()->json([
            'recurring_transactions' => $recurring,
            'summary' => [
                'total' => $recurring->count(),
                'active' => $recurring->where('status', 'active')->count(),
                'paused' => $recurring->where('status', 'paused')->count(),
                'due_today' => $recurring->where('is_due', true)->count(),
            ],
        ]);
    }

    /**
     * Get single recurring transaction
     */
    public function show(Request $request, RecurringTransaction $recurringTransaction)
    {
        if ($recurringTransaction->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $recurringTransaction->load([
            'category:id,name,icon,color',
            'account:id,name',
            'creator:id,name',
            'generatedTransactions'
        ]);

        return response()->json([
            'recurring_transaction' => $this->formatRecurringResponse($recurringTransaction, true),
        ]);
    }

    /**
     * Create recurring transaction
     */
    public function store(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => 'required|in:income,expense',
            'category_id' => 'required|exists:categories,id',
            'account_id' => 'nullable|exists:accounts,id',
            'merchant' => 'nullable|string|max:255',
            'amount' => 'required|integer|min:1',
            'frequency' => 'required|in:daily,weekly,monthly,yearly',
            'interval' => 'nullable|integer|min:1|max:365',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after:start_date',
            'max_occurrences' => 'nullable|integer|min:1',
            'auto_create' => 'nullable|boolean',
            'send_notification' => 'nullable|boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        DB::beginTransaction();
        try {
            // Calculate first occurrence
            $recurring = RecurringTransaction::create([
                'household_id' => $household->id,
                'created_by' => $request->user()->id,
                ...$validated,
                'interval' => $validated['interval'] ?? 1,
                'next_occurrence' => $validated['start_date'],
                'auto_create' => $validated['auto_create'] ?? true,
                'send_notification' => $validated['send_notification'] ?? true,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Recurring transaction created successfully',
                'recurring_transaction' => $this->formatRecurringResponse($recurring->fresh()),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create recurring transaction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update recurring transaction
     */
    public function update(Request $request, RecurringTransaction $recurringTransaction)
    {
        if ($recurringTransaction->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category_id' => 'sometimes|exists:categories,id',
            'account_id' => 'nullable|exists:accounts,id',
            'merchant' => 'nullable|string|max:255',
            'amount' => 'sometimes|integer|min:1',
            'end_date' => 'nullable|date|after:start_date',
            'max_occurrences' => 'nullable|integer|min:1',
            'auto_create' => 'sometimes|boolean',
            'send_notification' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        $recurringTransaction->update($validated);

        return response()->json([
            'message' => 'Recurring transaction updated successfully',
            'recurring_transaction' => $this->formatRecurringResponse($recurringTransaction->fresh()),
        ]);
    }

    /**
     * Delete recurring transaction
     */
    public function destroy(Request $request, RecurringTransaction $recurringTransaction)
    {
        if ($recurringTransaction->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $recurringTransaction->delete();

        return response()->json([
            'message' => 'Recurring transaction deleted successfully',
        ]);
    }

    /**
     * Pause recurring transaction
     */
    public function pause(Request $request, RecurringTransaction $recurringTransaction)
    {
        if ($recurringTransaction->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($recurringTransaction->status !== 'active') {
            return response()->json([
                'message' => 'Only active recurring transactions can be paused',
            ], 400);
        }

        $recurringTransaction->pause();

        return response()->json([
            'message' => 'Recurring transaction paused successfully',
            'recurring_transaction' => $this->formatRecurringResponse($recurringTransaction->fresh()),
        ]);
    }

    /**
     * Resume recurring transaction
     */
    public function resume(Request $request, RecurringTransaction $recurringTransaction)
    {
        if ($recurringTransaction->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$recurringTransaction->resume()) {
            return response()->json([
                'message' => 'Failed to resume recurring transaction',
            ], 400);
        }

        return response()->json([
            'message' => 'Recurring transaction resumed successfully',
            'recurring_transaction' => $this->formatRecurringResponse($recurringTransaction->fresh()),
        ]);
    }

    /**
     * Cancel recurring transaction
     */
    public function cancel(Request $request, RecurringTransaction $recurringTransaction)
    {
        if ($recurringTransaction->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $recurringTransaction->cancel();

        return response()->json([
            'message' => 'Recurring transaction cancelled successfully',
            'recurring_transaction' => $this->formatRecurringResponse($recurringTransaction->fresh()),
        ]);
    }

    /**
     * Manually generate transaction now
     */
    public function generateNow(Request $request, RecurringTransaction $recurringTransaction)
    {
        if ($recurringTransaction->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($recurringTransaction->status !== 'active') {
            return response()->json([
                'message' => 'Only active recurring transactions can generate',
            ], 400);
        }

        DB::beginTransaction();
        try {
            $transaction = $recurringTransaction->generateTransaction();

            if (!$transaction) {
                return response()->json([
                    'message' => 'Failed to generate transaction',
                ], 400);
            }

            DB::commit();

            return response()->json([
                'message' => 'Transaction generated successfully',
                'transaction' => [
                    'id' => $transaction->id,
                    'merchant' => $transaction->merchant,
                    'amount' => $transaction->total,
                    'formatted_amount' => $transaction->getFormattedTotal(),
                ],
                'recurring_transaction' => $this->formatRecurringResponse($recurringTransaction->fresh()),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to generate transaction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Format recurring response
     */
    private function formatRecurringResponse(RecurringTransaction $recurring, bool $includeGenerated = false): array
    {
        $data = [
            'id' => $recurring->id,
            'name' => $recurring->name,
            'description' => $recurring->description,
            'type' => $recurring->type,
            'merchant' => $recurring->merchant,
            'amount' => $recurring->amount,
            'formatted_amount' => $recurring->getFormattedAmount(),
            'frequency' => $recurring->frequency,
            'frequency_label' => $recurring->getFrequencyLabel(),
            'interval' => $recurring->interval,
            'start_date' => $recurring->start_date->format('Y-m-d'),
            'end_date' => $recurring->end_date?->format('Y-m-d'),
            'next_occurrence' => $recurring->next_occurrence->format('Y-m-d'),
            'occurrences_count' => $recurring->occurrences_count,
            'max_occurrences' => $recurring->max_occurrences,
            'status' => $recurring->status,
            'auto_create' => $recurring->auto_create,
            'send_notification' => $recurring->send_notification,
            'is_due' => $recurring->isDue(),
            'notes' => $recurring->notes,
            'category' => $recurring->category ? [
                'id' => $recurring->category->id,
                'name' => $recurring->category->name,
                'icon' => $recurring->category->icon,
                'color' => $recurring->category->color,
            ] : null,
            'account' => $recurring->account ? [
                'id' => $recurring->account->id,
                'name' => $recurring->account->name,
            ] : null,
            'creator' => [
                'id' => $recurring->creator->id,
                'name' => $recurring->creator->name,
            ],
            'created_at' => $recurring->created_at,
            'updated_at' => $recurring->updated_at,
        ];

        if ($includeGenerated) {
            $data['generated_transactions'] = $recurring->generatedTransactions()
                ->orderBy('tanggal', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($t) {
                    return [
                        'id' => $t->id,
                        'merchant' => $t->merchant,
                        'tanggal' => $t->tanggal->format('Y-m-d'),
                        'amount' => $t->total,
                        'formatted_amount' => $t->getFormattedTotal(),
                    ];
                });
        }

        return $data;
    }
}