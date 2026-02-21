<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoanController extends Controller
{
    public function index(Request $request)
    {
        $householdId = $request->user()->household_id;
        $loans = Loan::where('household_id', $householdId)
            ->with('account')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $loans]);
    }

    public function show(Request $request, Loan $loan)
    {
        if ($loan->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $loan->load(['account', 'transactions' => function ($q) {
            $q->orderBy('tanggal', 'desc');
        }]);

        return response()->json(['data' => $loan]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'account_id' => 'required|exists:accounts,id',
            'name' => 'required|string|max:255',
            'principal_amount' => 'required|integer|min:0',
            'interest_amount' => 'required|integer|min:0',
            'tenor_months' => 'required|integer|min:1',
            'initial_paid_amount' => 'nullable|integer|min:0',
            'initial_paid_periods' => 'nullable|integer|min:0',
            'installment_amount' => 'required|integer|min:0',
            'start_date' => 'required|date',
            'target_end_date' => 'required|date',
            'next_payment_date' => 'required|date',
            'create_disbursement_transaction' => 'boolean',
            'reminder_enabled' => 'boolean',
            'reminder_days' => 'integer|min:1'
        ]);

        $householdId = $request->user()->household_id;

        try {
            DB::beginTransaction();

            $totalAmount = $validated['principal_amount'] + $validated['interest_amount'];
            $initialPaid = $validated['initial_paid_amount'] ?? 0;
            $initialPeriods = $validated['initial_paid_periods'] ?? 0;

            if ($totalAmount > 0 && $totalAmount < $validated['installment_amount']) {
                return response()->json([
                    'message' => 'Total Pokok + Bunga (Rp ' . number_format($totalAmount, 0, ',', '.') . ') lebih kecil dari Cicilan per bulan (Rp ' . number_format($validated['installment_amount'], 0, ',', '.') . '). Pastikan Anda memasukkan TOTAL pinjaman, bukan nilai per bulan.'
                ], 422);
            }

            $status = ($initialPaid >= $totalAmount) ? 'paid_off' : 'active';

            $loan = Loan::create([
                'household_id' => $householdId,
                'created_by' => $request->user()->id,
                'account_id' => $validated['account_id'],
                'name' => $validated['name'],
                'principal_amount' => $validated['principal_amount'],
                'interest_amount' => $validated['interest_amount'],
                'total_amount' => $totalAmount,
                'paid_amount' => $initialPaid,
                'initial_paid_amount' => $initialPaid,
                'tenor_months' => $validated['tenor_months'],
                'paid_periods' => $initialPeriods,
                'initial_paid_periods' => $initialPeriods,
                'installment_amount' => $validated['installment_amount'],
                'start_date' => $validated['start_date'],
                'target_end_date' => $validated['target_end_date'],
                'next_payment_date' => $validated['next_payment_date'],
                'last_payment_date' => null,
                'status' => $status,
                'reminder_enabled' => $validated['reminder_enabled'] ?? true,
                'reminder_days' => $validated['reminder_days'] ?? 3,
            ]);

            // Create disbursement transaction if requested
            if ($request->input('create_disbursement_transaction', true) && $validated['principal_amount'] > 0) {
                // Find fallback income category
                $category = Category::where('household_id', null)->where('type', 'income')->first();

                Transaction::create([
                    'household_id' => $householdId,
                    'created_by' => $request->user()->id,
                    'category_id' => $category->id ?? 1,
                    'account_id' => $validated['account_id'],
                    'loan_id' => $loan->id, // link back to loan
                    'type' => 'income',
                    'merchant' => 'Pencairan Pinjaman: ' . $loan->name,
                    'tanggal' => $validated['start_date'],
                    'subtotal' => $validated['principal_amount'],
                    'diskon' => 0,
                    'total' => $validated['principal_amount'],
                    'source' => 'system',
                    'notes' => 'Disbursement for loan ' . $loan->name,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Loan created successfully',
                'data' => $loan
            ], 201);

        }
        catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create loan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function pay(Request $request, Loan $loan)
    {
        if ($loan->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'amount' => 'required|integer|min:1',
            'date' => 'required|date',
            'account_id' => 'required|exists:accounts,id',
            'merchant' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            $category = Category::where('household_id', null)->where('name', 'Cicilan & Utang')->first();

            Transaction::create([
                'household_id' => $loan->household_id,
                'created_by' => $request->user()->id,
                'category_id' => $category->id ?? 1, // Fallback if missing
                'account_id' => $validated['account_id'],
                'loan_id' => $loan->id,
                'type' => 'expense',
                'merchant' => $validated['merchant'] ?? 'Pembayaran Cicilan: ' . $loan->name,
                'tanggal' => $validated['date'],
                'subtotal' => $validated['amount'],
                'diskon' => 0,
                'total' => $validated['amount'],
                'source' => 'manual',
                'notes' => $validated['notes'] ?? 'Auto-generated loan installment',
            ]);

            $loan->recalculate();

            DB::commit();

            return response()->json([
                'message' => 'Loan payment recorded successfully',
                'data' => $loan->fresh()
            ]);

        }
        catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to record payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, Loan $loan)
    {
        if ($loan->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            DB::beginTransaction();

            // Transactions linked to loan_id usually nullOut via DB constraints (nullOnDelete)
            // But we actually want to delete the transactions representing the loan entirely?
            // Usually, deleting the loan shouldn't orphan transactions, they should either be deleted or detached.
            // Since they are intrinsically part of the loan, let's delete them.
            // Wait, what if someone spent the disbursement money? 
            // If we delete the loan, we remove the "Pencairan" income and the "Pembayaran" expenses.
            // This is arguably proper behavior to reverse the whole thing from the budget.
            $loan->transactions()->delete();

            $loan->delete();

            DB::commit();

            return response()->json([
                'message' => 'Loan deleted successfully'
            ]);

        }
        catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete loan',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}