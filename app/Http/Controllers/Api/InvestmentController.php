<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Investment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvestmentController extends Controller
{
    /**
     * Get all investments
     */
    public function index(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'status' => 'nullable|in:active,sold,archived',
            'type' => 'nullable|in:stocks,mutual_funds,bonds,crypto,gold,property,deposit,other',
        ]);

        $query = Investment::forHousehold($household->id)
            ->with(['account:id,name', 'creator:id,name']);

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        $investments = $query->orderBy('created_at', 'desc')
                            ->get()
                            ->map(function ($inv) {
                                return $this->formatInvestmentResponse($inv);
                            });

        // Portfolio summary
        $summary = [
            'total_investments' => $investments->count(),
            'total_initial' => $investments->sum('initial_amount'),
            'total_current' => $investments->sum('current_value'),
            'total_profit_loss' => $investments->sum(fn($i) => $i['profit_loss']),
            'formatted_total_initial' => 'Rp ' . number_format($investments->sum('initial_amount'), 0, ',', '.'),
            'formatted_total_current' => 'Rp ' . number_format($investments->sum('current_value'), 0, ',', '.'),
            'formatted_total_pl' => $this->formatPL($investments->sum(fn($i) => $i['profit_loss'])),
            'average_roi' => $investments->avg('roi'),
        ];

        // By type breakdown
        $byType = $investments->groupBy('type')->map(function ($group, $type) {
            return [
                'type' => $type,
                'count' => $group->count(),
                'total_value' => $group->sum('current_value'),
                'formatted_value' => 'Rp ' . number_format($group->sum('current_value'), 0, ',', '.'),
            ];
        })->values();

        return response()->json([
            'investments' => $investments,
            'summary' => $summary,
            'by_type' => $byType,
        ]);
    }

    /**
     * Get single investment
     */
    public function show(Request $request, Investment $investment)
    {
        if ($investment->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $investment->load([
            'account:id,name',
            'creator:id,name',
            'transactions'
        ]);

        return response()->json([
            'investment' => $this->formatInvestmentResponse($investment, true),
        ]);
    }

    /**
     * Create investment
     */
    public function store(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'symbol' => 'nullable|string|max:50',
            'type' => 'required|in:stocks,mutual_funds,bonds,crypto,gold,property,deposit,other',
            'account_id' => 'nullable|exists:accounts,id',
            'quantity' => 'required|numeric|min:0',
            'avg_buy_price' => 'required|integer|min:0',
            'platform' => 'nullable|string|max:255',
            'currency' => 'nullable|string|max:3',
            'purchase_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'icon' => 'nullable|string|max:10',
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        DB::beginTransaction();
        try {
            $initialAmount = round($validated['quantity'] * $validated['avg_buy_price']);

            $investment = Investment::create([
                'household_id' => $household->id,
                'created_by' => $request->user()->id,
                ...$validated,
                'initial_amount' => $initialAmount,
                'current_value' => $initialAmount, // Same as initial until updated
                'currency' => $validated['currency'] ?? 'IDR',
                'icon' => $validated['icon'] ?? '📈',
                'color' => $validated['color'] ?? '#3B82F6',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Investment created successfully',
                'investment' => $this->formatInvestmentResponse($investment->fresh()),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create investment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update investment
     */
    public function update(Request $request, Investment $investment)
    {
        if ($investment->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'symbol' => 'nullable|string|max:50',
            'current_price' => 'nullable|numeric|min:0',
            'platform' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'icon' => 'sometimes|string|max:10',
            'color' => 'sometimes|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'status' => 'sometimes|in:active,sold,archived',
        ]);

        // Update current value if price provided
        if (isset($validated['current_price'])) {
            $validated['current_value'] = round($investment->quantity * $validated['current_price']);
            $validated['last_updated_at'] = now();
        }

        $investment->update($validated);

        return response()->json([
            'message' => 'Investment updated successfully',
            'investment' => $this->formatInvestmentResponse($investment->fresh()),
        ]);
    }

    /**
     * Delete investment
     */
    public function destroy(Request $request, Investment $investment)
    {
        if ($investment->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $investment->delete();

        return response()->json([
            'message' => 'Investment deleted successfully',
        ]);
    }

    /**
     * Add buy transaction
     */
    public function buy(Request $request, Investment $investment)
    {
        if ($investment->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.00000001',
            'price_per_unit' => 'required|integer|min:1',
            'fee' => 'nullable|integer|min:0',
            'transaction_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        DB::beginTransaction();
        try {
            $investment->addBuyTransaction(
                $validated['quantity'],
                $validated['price_per_unit'],
                $validated['fee'] ?? 0,
                $validated['transaction_date'] ?? now()->toDateString()
            );

            DB::commit();

            return response()->json([
                'message' => 'Buy transaction added successfully',
                'investment' => $this->formatInvestmentResponse($investment->fresh()),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to add buy transaction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add sell transaction
     */
    public function sell(Request $request, Investment $investment)
    {
        if ($investment->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.00000001',
            'price_per_unit' => 'required|integer|min:1',
            'fee' => 'nullable|integer|min:0',
            'transaction_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validated['quantity'] > $investment->quantity) {
            return response()->json([
                'message' => 'Cannot sell more than owned quantity',
                'owned' => $investment->quantity,
            ], 400);
        }

        DB::beginTransaction();
        try {
            $investment->addSellTransaction(
                $validated['quantity'],
                $validated['price_per_unit'],
                $validated['fee'] ?? 0,
                $validated['transaction_date'] ?? now()->toDateString()
            );

            DB::commit();

            return response()->json([
                'message' => 'Sell transaction added successfully',
                'investment' => $this->formatInvestmentResponse($investment->fresh()),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to add sell transaction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update current price
     */
    public function updatePrice(Request $request, Investment $investment)
    {
        if ($investment->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'current_price' => 'required|numeric|min:0',
        ]);

        $investment->updateCurrentValue($validated['current_price']);

        return response()->json([
            'message' => 'Price updated successfully',
            'investment' => $this->formatInvestmentResponse($investment->fresh()),
        ]);
    }

    /**
     * Format investment response
     */
    private function formatInvestmentResponse(Investment $investment, bool $includeTransactions = false): array
    {
        $data = [
            'id' => $investment->id,
            'name' => $investment->name,
            'symbol' => $investment->symbol,
            'type' => $investment->type,
            'type_label' => $investment->getTypeLabel(),
            'quantity' => $investment->quantity,
            'avg_buy_price' => $investment->avg_buy_price,
            'formatted_avg_buy_price' => $investment->getFormattedAvgBuyPrice(),
            'initial_amount' => $investment->initial_amount,
            'formatted_initial' => $investment->getFormattedInitialAmount(),
            'current_value' => $investment->current_value,
            'formatted_current' => $investment->getFormattedCurrentValue(),
            'current_price' => $investment->current_price,
            'profit_loss' => $investment->getProfitLoss(),
            'formatted_profit_loss' => $investment->getFormattedProfitLoss(),
            'roi' => $investment->getROI(),
            'formatted_roi' => $investment->getFormattedROI(),
            'is_profit' => $investment->isProfit(),
            'is_loss' => $investment->isLoss(),
            'platform' => $investment->platform,
            'currency' => $investment->currency,
            'purchase_date' => $investment->purchase_date?->format('Y-m-d'),
            'notes' => $investment->notes,
            'icon' => $investment->icon,
            'color' => $investment->color,
            'status' => $investment->status,
            'last_updated_at' => $investment->last_updated_at,
            'account' => $investment->account ? [
                'id' => $investment->account->id,
                'name' => $investment->account->name,
            ] : null,
            'creator' => [
                'id' => $investment->creator->id,
                'name' => $investment->creator->name,
            ],
            'created_at' => $investment->created_at,
            'updated_at' => $investment->updated_at,
        ];

        if ($includeTransactions) {
            $data['transactions'] = $investment->transactions()
                ->orderBy('transaction_date', 'desc')
                ->get()
                ->map(function ($t) {
                    return [
                        'id' => $t->id,
                        'type' => $t->type,
                        'type_label' => $t->getTypeLabel(),
                        'quantity' => $t->quantity,
                        'price_per_unit' => $t->price_per_unit,
                        'formatted_price' => $t->getFormattedPricePerUnit(),
                        'total_amount' => $t->total_amount,
                        'formatted_total' => $t->getFormattedTotal(),
                        'fee' => $t->fee,
                        'formatted_fee' => $t->getFormattedFee(),
                        'transaction_date' => $t->transaction_date->format('Y-m-d'),
                        'notes' => $t->notes,
                    ];
                });
        }

        return $data;
    }

    private function formatPL(int $amount): string
    {
        $formatted = 'Rp ' . number_format(abs($amount), 0, ',', '.');
        return $amount >= 0 ? "+{$formatted}" : "-{$formatted}";
    }
}