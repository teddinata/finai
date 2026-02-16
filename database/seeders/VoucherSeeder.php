<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Voucher;
use App\Models\User;

class VoucherSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        $createdBy = $admin ? $admin->id : 1;

        $vouchers = [
            [
                'code' => 'BENAHLAUNCH',
                'name' => 'Grand Opening',
                'type' => 'percentage',
                'value' => 50,
                'max_uses' => 20,
                'valid_from' => now(),
                'valid_until' => now()->addMonths(3),
                'is_active' => true,
                'created_by' => $createdBy,
            ],
            [
                'code' => 'VVIP99',
                'name' => 'Very Special Discount',
                'type' => 'percentage',
                'value' => 99,
                'max_uses' => 10,
                'valid_from' => now(),
                'valid_until' => now()->addMonths(3),
                'is_active' => true,
                'created_by' => $createdBy,
            ],
            [
                'code' => 'VVIP90',
                'name' => 'Very Special Discount',
                'type' => 'percentage',
                'value' => 90,
                'max_uses' => 1,
                'valid_from' => now(),
                'valid_until' => now()->addMonths(3),
                'is_active' => true,
                'created_by' => $createdBy,
            ],
            [
                'code' => 'VIP80',
                'name' => 'Special Discount',
                'type' => 'percentage',
                'value' => 80,
                'max_uses' => 2,
                'valid_from' => now(),
                'valid_until' => now()->addMonths(3),
                'is_active' => true,
                'created_by' => $createdBy,
            ],
        ];

        foreach ($vouchers as $voucher) {
            Voucher::updateOrCreate(
            ['code' => $voucher['code']],
                $voucher
            );
        }
    }
}