<?php
// database/seeders/AccountSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Account;

class AccountSeeder extends Seeder
{
    public function run(): void
    {
        $defaultAccounts = [
            [
                'name' => 'Cash',
                'type' => 'cash',
                'icon' => '💵',
                'color' => '#10B981',
                'sort_order' => 1,
            ],
            [
                'name' => 'Bank Account',
                'type' => 'bank',
                'icon' => '🏦',
                'color' => '#3B82F6',
                'sort_order' => 2,
            ],
            [
                'name' => 'E-Wallet',
                'type' => 'ewallet',
                'icon' => '📱',
                'color' => '#F59E0B',
                'sort_order' => 3,
            ],
        ];

        // This would normally be created per household during registration
        // For testing, we can create for existing households
    }
}