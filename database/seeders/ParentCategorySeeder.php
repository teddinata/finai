<?php
// database/seeders/ParentCategorySeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ParentCategory;

class ParentCategorySeeder extends Seeder
{
    public function run(): void
    {
        $parentCategories = [
            [
                'name' => 'Kebutuhan',
                'slug' => 'needs',
                'icon' => '🏠',
                'color' => '#EF4444',
                'description' => 'Pengeluaran esensial yang harus dipenuhi (makanan, transportasi, tagihan, kesehatan)',
                'sort_order' => 1,
            ],
            [
                'name' => 'Keinginan',
                'slug' => 'wants',
                'icon' => '🎉',
                'color' => '#F59E0B',
                'description' => 'Pengeluaran non-esensial untuk gaya hidup (hiburan, hobi, fashion)',
                'sort_order' => 2,
            ],
            [
                'name' => 'Tabungan & Investasi',
                'slug' => 'savings',
                'icon' => '💰',
                'color' => '#10B981',
                'description' => 'Simpanan, investasi, dan dana darurat',
                'sort_order' => 3,
            ],
        ];

        foreach ($parentCategories as $category) {
            ParentCategory::create($category);
        }
    }
}