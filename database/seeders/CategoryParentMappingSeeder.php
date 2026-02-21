<?php
// database/seeders/CategoryParentMappingSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\ParentCategory;

class CategoryParentMappingSeeder extends Seeder
{
    public function run(): void
    {
        $needs = ParentCategory::where('slug', 'needs')->first();
        $wants = ParentCategory::where('slug', 'wants')->first();
        $savings = ParentCategory::where('slug', 'savings')->first();

        // Expense categories mapping
        $needsCategories = [
            'Makanan & Minuman',
            'Transportasi',
            'Belanja Kebutuhan',
            'Tagihan & Utilitas',
            'Kesehatan',
            'Pendidikan',
            'Rumah Tangga',
            'Asuransi',
            'Pajak',
            'Perawatan Kendaraan',
            'Cicilan & Utang',
        ];

        $wantsCategories = [
            'Hiburan',
            'Fashion & Kecantikan',
            'Olahraga & Fitness',
            'Hobi',
            'Hadiah & Donasi',
            'Traveling',
        ];

        $savingsCategories = [
            'Investasi',
        ];

        // Update needs
        Category::where('type', 'expense')
            ->whereIn('name', $needsCategories)
            ->update(['parent_category_id' => $needs->id]);

        // Update wants
        Category::where('type', 'expense')
            ->whereIn('name', $wantsCategories)
            ->update(['parent_category_id' => $wants->id]);

        // Update savings
        Category::where('type', 'expense')
            ->whereIn('name', $savingsCategories)
            ->update(['parent_category_id' => $savings->id]);

        // Income categories → savings
        Category::where('type', 'income')
            ->update(['parent_category_id' => $savings->id]);
    }
}