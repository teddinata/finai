<?php

use App\Models\User;
use App\Models\Transaction;
use App\Models\Household;
use Carbon\Carbon;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Debug Analytics Script\n";
echo "Date Range: " . now()->startOfMonth()->toDateString() . " to " . now()->endOfMonth()->toDateString() . "\n\n";

$users = User::with('household')->get();

foreach ($users as $user) {
    echo "User: {$user->name} (ID: {$user->id})\n";
    
    if ($user->household) {
        echo "  Household: {$user->household->name} (ID: {$user->household->id})\n";
        
        $count = Transaction::where('household_id', $user->household->id)->count();
        echo "  Total Transactions: $count\n";
        
        $thisMonth = Transaction::where('household_id', $user->household->id)
            ->whereBetween('tanggal', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
        echo "  This Month: $thisMonth\n";

        if ($thisMonth > 0) {
            $sum = Transaction::where('household_id', $user->household->id)
                ->whereBetween('tanggal', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('total');
            echo "  Total Amount This Month: " . number_format($sum) . "\n";
        } else {
             $latest = Transaction::where('household_id', $user->household->id)->latest('tanggal')->first();
             if ($latest) {
                 echo "  Latest Transaction: {$latest->tanggal->format('Y-m-d')} (Amount: " . number_format($latest->total) . ")\n";
             }
        }

    } else {
        echo "  No Household.\n";
    }
    echo "-------------------\n";
}