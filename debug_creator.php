<?php

use App\Models\User;
use App\Models\Transaction;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Debug Transactions by Creator\n";

$users = User::all();

foreach ($users as $user) {
    echo "User: {$user->name} (ID: {$user->id})\n";
    $count = Transaction::where('created_by', $user->id)->count();
    echo "  Created Transactions: $count\n";

    if ($count > 0) {
        $sample = Transaction::where('created_by', $user->id)->first();
        echo "  Sample Household ID: {$sample->household_id}\n";

        // Check mismatch
        $mismatch = Transaction::where('created_by', $user->id)
            ->where('household_id', '!=', $user->household_id)
            ->count();
        echo "  Household Mismatch: $mismatch\n";
    }
    echo "-------------------\n";
}