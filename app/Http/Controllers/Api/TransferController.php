<?php
// app/Http/Controllers/Api/TransferController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transfer;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransferController extends Controller
{
    public function index(Request $request)
    {
        $household = $request->user()->household;

        $query = Transfer::forHousehold($household->id)
            ->with(['fromAccount', 'toAccount', 'creator']);

        // Filters
        if ($request->has('account_id')) {
            $query->forAccount($request->account_id);
        }

        if ($request->has('start_date')) {
            $query->where('tanggal', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->where('tanggal', '<=', $request->end_date);
        }

        $transfers = $query->orderBy('tanggal', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'transfers' => $transfers->through(function ($transfer) {
                return $this->formatTransferResponse($transfer);
            }),
        ]);
    }

    public function store(Request $request)
    {
        $household = $request->user()->household;

        $validated = $request->validate([
            'from_account_id' => 'required|exists:accounts,id',
            'to_account_id' => 'required|exists:accounts,id|different:from_account_id',
            'amount' => 'required|integer|min:1',
            'tanggal' => 'required|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Verify both accounts belong to household
        $fromAccount = Account::find($validated['from_account_id']);
        $toAccount = Account::find($validated['to_account_id']);

        if ($fromAccount->household_id !== $household->id || $toAccount->household_id !== $household->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $transfer = Transfer::create([
                'household_id' => $household->id,
                'created_by' => $request->user()->id,
                ...$validated,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Transfer created successfully',
                'transfer' => $this->formatTransferResponse($transfer->load(['fromAccount', 'toAccount'])),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create transfer',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Request $request, Transfer $transfer)
    {
        if ($transfer->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $transfer->load(['fromAccount', 'toAccount', 'creator']);

        return response()->json([
            'transfer' => $this->formatTransferResponse($transfer),
        ]);
    }

    public function update(Request $request, Transfer $transfer)
    {
        $household = $request->user()->household;

        if ($transfer->household_id !== $household->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'from_account_id' => 'sometimes|exists:accounts,id',
            'to_account_id' => 'sometimes|exists:accounts,id',
            'amount' => 'sometimes|integer|min:1',
            'tanggal' => 'sometimes|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Verify accounts if changed
        if (isset($validated['from_account_id']) || isset($validated['to_account_id'])) {
            $fromId = $validated['from_account_id'] ?? $transfer->from_account_id;
            $toId = $validated['to_account_id'] ?? $transfer->to_account_id;

            if ($fromId === $toId) {
                return response()->json(['message' => 'Cannot transfer to same account'], 400);
            }

            $fromAccount = Account::find($fromId);
            $toAccount = Account::find($toId);

            if ($fromAccount->household_id !== $household->id || $toAccount->household_id !== $household->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        DB::beginTransaction();
        try {
            $transfer->update($validated);

            DB::commit();

            return response()->json([
                'message' => 'Transfer updated successfully',
                'transfer' => $this->formatTransferResponse($transfer->load(['fromAccount', 'toAccount'])),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update transfer',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, Transfer $transfer)
    {
        if ($transfer->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $transfer->delete();

            DB::commit();

            return response()->json([
                'message' => 'Transfer deleted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete transfer',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function formatTransferResponse(Transfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'from_account' => [
                'id' => $transfer->fromAccount->id,
                'name' => $transfer->fromAccount->name,
                'icon' => $transfer->fromAccount->icon,
                'color' => $transfer->fromAccount->color,
            ],
            'to_account' => [
                'id' => $transfer->toAccount->id,
                'name' => $transfer->toAccount->name,
                'icon' => $transfer->toAccount->icon,
                'color' => $transfer->toAccount->color,
            ],
            'amount' => $transfer->amount,
            'formatted_amount' => $transfer->getFormattedAmount(),
            'tanggal' => $transfer->tanggal->format('Y-m-d'),
            'notes' => $transfer->notes,
            'creator' => [
                'id' => $transfer->creator->id,
                'name' => $transfer->creator->name,
            ],
            'created_at' => $transfer->created_at,
            'updated_at' => $transfer->updated_at,
        ];
    }
}