<?php
// app/Http/Controllers/Api/NetWorthController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NetWorthService;
use Illuminate\Http\Request;

/**
 * Angka posisi keuangan (stock): tidak difilter periode, hanya bisa
 * digeser titik waktunya lewat parameter as_of.
 */
class NetWorthController extends Controller
{
    public function __construct(private NetWorthService $netWorth)
    {
    }

    public function show(Request $request)
    {
        $validated = $request->validate([
            'as_of' => 'nullable|date',
        ]);

        $householdId = $request->user()->household_id;
        $asOf = $this->normalizeAsOf($validated['as_of'] ?? null);

        return response()->json([
            'net_worth' => $this->netWorth->snapshot($householdId, $asOf),
            'accounts' => $this->netWorth->accountBreakdown($householdId, $asOf),
        ]);
    }

    public function history(Request $request)
    {
        $validated = $request->validate([
            'months' => 'nullable|integer|min:2|max:60',
        ]);

        return response()->json(
            $this->netWorth->history($request->user()->household_id, $validated['months'] ?? 12)
        );
    }

    public function safeToSpend(Request $request)
    {
        return response()->json(
            $this->netWorth->safeToSpend($request->user()->household_id)
        );
    }

    /**
     * Tanggal hari ini atau di masa depan diperlakukan sebagai "sekarang",
     * supaya saldo yang sudah di-cache bisa dipakai langsung.
     */
    private function normalizeAsOf(?string $asOf): ?string
    {
        if ($asOf === null) {
            return null;
        }

        return $asOf >= now()->toDateString() ? null : $asOf;
    }
}
