<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Create superadmin
        User::create([
            'name' => 'Super Admin',
            'email' => 'admin@benah.app',
            'password' => Hash::make('admin123'), // Change in production!
            'role' => 'admin',
            'household_id' => null,
            'is_billing_owner' => false,
            'active' => true,
            'email_verified_at' => now(),
        ]);

        $this->command->info('✅ Admin user created: admin@benah.app / admin123');
    }
}