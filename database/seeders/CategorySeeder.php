<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        // Income Categories
        $incomeCategories = [
            ['name' => 'Gaji', 'icon' => '💼', 'color' => '#10B981', 'type' => 'income', 'sort_order' => 1],
            ['name' => 'Bonus', 'icon' => '🎉', 'color' => '#F59E0B', 'type' => 'income', 'sort_order' => 2],
            ['name' => 'Investasi', 'icon' => '📈', 'color' => '#3B82F6', 'type' => 'income', 'sort_order' => 3],
            ['name' => 'Bisnis', 'icon' => '💼', 'color' => '#8B5CF6', 'type' => 'income', 'sort_order' => 4],
            ['name' => 'Freelance', 'icon' => '💻', 'color' => '#06B6D4', 'type' => 'income', 'sort_order' => 5],
            ['name' => 'Dividen', 'icon' => '💰', 'color' => '#14B8A6', 'type' => 'income', 'sort_order' => 6],
            ['name' => 'Bunga Bank', 'icon' => '🏦', 'color' => '#6366F1', 'type' => 'income', 'sort_order' => 7],
            ['name' => 'Hadiah', 'icon' => '🎁', 'color' => '#EC4899', 'type' => 'income', 'sort_order' => 8],
            ['name' => 'Penjualan', 'icon' => '🛍️', 'color' => '#F97316', 'type' => 'income', 'sort_order' => 9],
            ['name' => 'Sewa', 'icon' => '🏡', 'color' => '#84CC16', 'type' => 'income', 'sort_order' => 10],
            ['name' => 'Cashback', 'icon' => '🎫', 'color' => '#A855F7', 'type' => 'income', 'sort_order' => 11],
            ['name' => 'Lainnya', 'icon' => '💵', 'color' => '#6B7280', 'type' => 'income', 'sort_order' => 12],
        ];

        // Expense Categories
        $expenseCategories = [
            ['name' => 'Makanan & Minuman', 'icon' => '🍔', 'color' => '#EF4444', 'type' => 'expense', 'sort_order' => 1],
            ['name' => 'Transportasi', 'icon' => '🚗', 'color' => '#3B82F6', 'type' => 'expense', 'sort_order' => 2],
            ['name' => 'Belanja Kebutuhan', 'icon' => '🛒', 'color' => '#10B981', 'type' => 'expense', 'sort_order' => 3],
            ['name' => 'Tagihan & Utilitas', 'icon' => '💡', 'color' => '#F59E0B', 'type' => 'expense', 'sort_order' => 4],
            ['name' => 'Hiburan', 'icon' => '🎬', 'color' => '#8B5CF6', 'type' => 'expense', 'sort_order' => 5],
            ['name' => 'Kesehatan', 'icon' => '💊', 'color' => '#EC4899', 'type' => 'expense', 'sort_order' => 6],
            ['name' => 'Pendidikan', 'icon' => '📚', 'color' => '#14B8A6', 'type' => 'expense', 'sort_order' => 7],
            ['name' => 'Fashion & Kecantikan', 'icon' => '👗', 'color' => '#F43F5E', 'type' => 'expense', 'sort_order' => 8],
            ['name' => 'Rumah Tangga', 'icon' => '🏠', 'color' => '#6366F1', 'type' => 'expense', 'sort_order' => 9],
            ['name' => 'Olahraga & Fitness', 'icon' => '⚽', 'color' => '#059669', 'type' => 'expense', 'sort_order' => 10],
            ['name' => 'Hobi', 'icon' => '🎨', 'color' => '#D946EF', 'type' => 'expense', 'sort_order' => 11],
            ['name' => 'Asuransi', 'icon' => '🛡️', 'color' => '#0891B2', 'type' => 'expense', 'sort_order' => 12],
            ['name' => 'Investasi', 'icon' => '📊', 'color' => '#16A34A', 'type' => 'expense', 'sort_order' => 13],
            ['name' => 'Hadiah & Donasi', 'icon' => '🎁', 'color' => '#DC2626', 'type' => 'expense', 'sort_order' => 14],
            ['name' => 'Cicilan & Utang', 'icon' => '💳', 'color' => '#DC2626', 'type' => 'expense', 'sort_order' => 15],
            ['name' => 'Pajak', 'icon' => '🏛️', 'color' => '#78716C', 'type' => 'expense', 'sort_order' => 16],
            ['name' => 'Perawatan Kendaraan', 'icon' => '🔧', 'color' => '#0284C7', 'type' => 'expense', 'sort_order' => 17],
            ['name' => 'Traveling', 'icon' => '✈️', 'color' => '#06B6D4', 'type' => 'expense', 'sort_order' => 18],
            ['name' => 'Lainnya', 'icon' => '📦', 'color' => '#6B7280', 'type' => 'expense', 'sort_order' => 19],
        ];

        // Insert income categories
        foreach ($incomeCategories as $category) {
            Category::create([
                'household_id' => null,
                'type' => $category['type'],
                'name' => $category['name'],
                'icon' => $category['icon'],
                'color' => $category['color'],
                'sort_order' => $category['sort_order'],
                'is_default' => true,
            ]);
        }

        // Insert expense categories
        foreach ($expenseCategories as $category) {
            Category::create([
                'household_id' => null,
                'type' => $category['type'],
                'name' => $category['name'],
                'icon' => $category['icon'],
                'color' => $category['color'],
                'sort_order' => $category['sort_order'],
                'is_default' => true,
            ]);
        }
    }
}