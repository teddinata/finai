<?php

use App\Models\User;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Debug Transaction Types\n";

$users = User::with('household')->get();

foreach ($users as $user) {
    if (!$user->household)
        continue;

    echo "User: {$user->name} (ID: {$user->id})\n";

    $transactions = Transaction::where('household_id', $user->household->id)
        ->whereBetween('tanggal', [now()->startOfMonth(), now()->endOfMonth()])
        ->get();

    echo "  Transactions This Month: " . $transactions->count() . "\n";

    foreach ($transactions as $t) {
        echo "    ID: {$t->id} | Date: {$t->tanggal->format('Y-m-d')} | Type: {$t->type} | Total: " . number_format($t->total) . "\n";
    }

    // Check AnalyticsController logic simulation
    $totalIncome = Transaction::where('household_id', $user->household->id)
        ->where('type', 'income')
        ->whereBetween('tanggal', [now()->startOfMonth(), now()->endOfMonth()])
        ->sum('total');

    $totalExpense = Transaction::where('household_id', $user->household->id)
        ->where('type', 'expense')
        ->whereBetween('tanggal', [now()->startOfMonth(), now()->endOfMonth()])
        ->sum('total');

    echo "  Simulated Analytics -> Income: " . number_format($totalIncome) . " | Expense: " . number_format($totalExpense) . "\n";
    echo "-------------------\n";
}