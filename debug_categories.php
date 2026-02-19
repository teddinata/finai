<?php

use App\Models\User;
use App\Models\Transaction;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Debug Transaction Categories\n";

$users = User::all();

foreach ($users as $user) {
    if (!$user->household)
        continue;

    $transactions = Transaction::where('household_id', $user->household->id)
        ->where('type', 'income')
        ->with('category')
        ->get();

    foreach ($transactions as $t) {
        echo "User: {$user->name} | ID: {$t->id} | Type: {$t->type} | Category: " . ($t->category ? $t->category->name : 'NULL') . "\n";
    }
}