<?php
// app/Http/Controllers/Api/AccountController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        $household = $request->user()->household;

        $accounts = Account::forHousehold($household->id)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'type' => $account->type,
                    'type_label' => $account->getTypeLabel(),
                    'account_number' => $account->account_number,
                    'institution' => $account->institution,
                    'icon' => $account->icon,
                    'color' => $account->color,
                    'current_balance' => $account->current_balance,
                    'formatted_balance' => $account->getFormattedBalance(),
                    'include_in_total' => $account->include_in_total,
                ];
            });

        $totalBalance = Account::forHousehold($household->id)
            ->active()
            ->includedInTotal()
            ->sum('current_balance');

        return response()->json([
            'accounts' => $accounts,
            'total_balance' => $totalBalance,
            'formatted_total' => 'Rp ' . number_format($totalBalance , 0, ',', '.'),
        ]);
    }

    public function store(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:bank,cash,ewallet,credit_card,investment,savings,other',
            'account_number' => 'nullable|string|max:255',
            'institution' => 'nullable|string|max:255',
            'icon' => 'required|string|max:10',
            'color' => 'required|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'initial_balance' => 'required|integer',
            'include_in_total' => 'boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        $account = Account::create([
            'household_id' => $household->id,
            ...$validated,
            'current_balance' => $validated['initial_balance'],
            'sort_order' => Account::where('household_id', $household->id)->max('sort_order') + 1,
        ]);

        return response()->json([
            'message' => 'Account created successfully',
            'account' => $account,
        ], 201);
    }

    public function update(Request $request, Account $account)
    {
        $household = $request->user()->household;

        if ($account->household_id !== $household->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:bank,cash,ewallet,credit_card,investment,savings,other',
            'account_number' => 'nullable|string|max:255',
            'institution' => 'nullable|string|max:255',
            'icon' => 'sometimes|string|max:10',
            'color' => 'sometimes|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'include_in_total' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        $account->update($validated);

        return response()->json([
            'message' => 'Account updated successfully',
            'account' => $account,
        ]);
    }

    public function destroy(Request $request, Account $account)
    {
        $household = $request->user()->household;

        if ($account->household_id !== $household->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check if account has transactions
        if ($account->transactions()->exists()) {
            return response()->json([
                'message' => 'Cannot delete account with existing transactions',
            ], 400);
        }

        // Check if account has transfers
        if ($account->transfersFrom()->exists() || $account->transfersTo()->exists()) {
            return response()->json([
                'message' => 'Cannot delete account with existing transfers',
            ], 400);
        }

        $account->delete();

        return response()->json([
            'message' => 'Account deleted successfully',
        ]);
    }

    public function show(Request $request, Account $account)
    {
        $household = $request->user()->household;

        if ($account->household_id !== $household->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'type_label' => $account->getTypeLabel(),
                'account_number' => $account->account_number,
                'institution' => $account->institution,
                'icon' => $account->icon,
                'color' => $account->color,
                'initial_balance' => $account->initial_balance,
                'formatted_initial_balance' => $account->getFormattedInitialBalance(),
                'current_balance' => $account->current_balance,
                'formatted_balance' => $account->getFormattedBalance(),
                'include_in_total' => $account->include_in_total,
                'is_active' => $account->is_active,
                'notes' => $account->notes,
                'transaction_count' => $account->transactions()->count(),
                'transfer_count' => $account->transfersFrom()->count() + $account->transfersTo()->count(),
            ],
        ]);
    }
}