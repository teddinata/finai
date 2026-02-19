<?php

use App\Models\User;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Checking Orphaned Transactions...\n";

$orphaned = Transaction::whereNull('household_id')->count();
echo "Transactions with NULL household_id: $orphaned\n";

$allIds = Transaction::distinct()->pluck('household_id');
echo "Distinct household_ids in transactions table: " . json_encode($allIds) . "\n";

$users = User::all();
foreach ($users as $u) {
    echo "User {$u->id} ({$u->name}) -> Household " . ($u->household_id ?? 'NULL') . "\n";
}