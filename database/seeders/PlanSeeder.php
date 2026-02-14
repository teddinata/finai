<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Plan;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Premium',
                'slug' => 'premium-free',
                'type' => 'free',
                'price' => 0, // Monthly price (for free = 0)
                'currency' => 'IDR',
                'description' => 'Mulai kelola keuangan pribadi',
                'is_active' => true,
                'is_popular' => false,
                'sort_order' => 1,
                'features' => [
                    // USER & ACCESS
                    'max_users' => 1,
                    'invite_members' => false,
                    'web_access' => false,
                    
                    // LIMITASI
                    'max_transactions_per_month' => 100,
                    'max_ai_scans_per_month' => 5,
                    'max_accounts' => 2,
                    'storage_mb' => 100,
                    
                    // MODUL AKSES
                    'dashboard' => 'basic',
                    'transactions' => true,
                    'categories' => 'default',
                    'accounts' => 'limited',
                    'budget' => false,
                    'analytics' => false,
                    'assets' => false,
                    'debts' => false,
                    
                    // FITUR
                    'export_data' => false,
                    'custom_categories' => false,
                    'recurring_transactions' => false,
                    'notifications' => false,
                    'reports' => 'last_30_days',
                    'multi_currency' => false,
                    'api_access' => false,
                    'priority_support' => false,
                ],
            ],
            [
                'name' => 'Pertalite',
                'slug' => 'pertalite',
                'type' => 'monthly', // Default type
                'price' => 100, // Monthly price (Rp 10.000 in cents)
                'currency' => 'IDR',
                'description' => 'Tracking lengkap untuk keluarga kecil',
                'is_active' => true,
                'is_popular' => true,
                'sort_order' => 2,
                'features' => [
                    // PRICING VARIATIONS
                    'price_monthly' => 10000, // Rp 10.000
                    'price_yearly' => 49000,  // Rp 49.000 (save 51rb)
                    
                    // USER & ACCESS
                    'max_users' => 3,
                    'invite_members' => true,
                    'web_access' => false,
                    
                    // LIMITASI
                    'max_transactions_per_month' => 500,
                    'max_ai_scans_per_month' => 100,
                    'max_accounts' => -1,
                    'storage_mb' => 500,
                    
                    // MODUL AKSES
                    'dashboard' => 'advanced',
                    'transactions' => true,
                    'categories' => 'custom',
                    'accounts' => true,
                    'budget' => true,
                    'analytics' => 'basic',
                    'assets' => true,
                    'debts' => false,
                    
                    // FITUR
                    'export_data' => 'pdf',
                    'custom_categories' => true,
                    'recurring_transactions' => true,
                    'notifications' => 'in_app',
                    'reports' => 'last_12_months',
                    'budget_templates' => ['50/30/20'],
                    'multi_currency' => false,
                    'api_access' => false,
                    'priority_support' => false,
                ],
            ],
            [
                'name' => 'Pertamax',
                'slug' => 'pertamax',
                'type' => 'monthly',
                'price' => 900, // Monthly price (Rp 19.000 in cents)
                'currency' => 'IDR',
                'description' => 'Kontrol penuh keuangan keluarga',
                'is_active' => true,
                'is_popular' => false,
                'sort_order' => 3,
                'features' => [
                    // PRICING VARIATIONS
                    'price_monthly' => 19000, // Rp 19.000
                    'price_yearly' => 99000,  // Rp 99.000 (save 129rb)
                    
                    // USER & ACCESS
                    'max_users' => 6,
                    'invite_members' => true,
                    'web_access' => true,
                    
                    // LIMITASI
                    'max_transactions_per_month' => 2000,
                    'max_ai_scans_per_month' => 500,
                    'max_accounts' => -1,
                    'storage_mb' => 2000,
                    
                    // MODUL AKSES
                    'dashboard' => 'comprehensive',
                    'transactions' => true,
                    'categories' => 'custom',
                    'accounts' => true,
                    'budget' => true,
                    'analytics' => 'advanced',
                    'assets' => true,
                    'debts' => true,
                    'networth' => 'basic',
                    
                    // FITUR
                    'export_data' => 'all',
                    'custom_categories' => true,
                    'recurring_transactions' => true,
                    'notifications' => 'multi_channel',
                    'reports' => 'unlimited',
                    'budget_templates' => 'all',
                    'multi_currency' => true,
                    'scheduled_exports' => 'monthly',
                    'api_access' => false,
                    'priority_support' => false,
                ],
            ],
            [
                'name' => 'Turbo',
                'slug' => 'turbo',
                'type' => 'lifetime',
                'price' => 1000, // Rp 149.000 in cents (lifetime)
                'currency' => 'IDR',
                'description' => 'Sekali bayar, pakai selamanya',
                'is_active' => true,
                'is_popular' => false,
                'sort_order' => 4,
                'features' => [
                    // USER & ACCESS
                    'max_users' => -1,
                    'invite_members' => true,
                    'web_access' => true,
                    
                    // LIMITASI
                    'max_transactions_per_month' => -1,
                    'max_ai_scans_per_month' => -1,
                    'max_accounts' => -1,
                    'storage_mb' => 10000,
                    
                    // MODUL AKSES
                    'dashboard' => 'ai_powered',
                    'transactions' => true,
                    'categories' => 'custom',
                    'accounts' => true,
                    'budget' => true,
                    'analytics' => 'ai_powered',
                    'assets' => true,
                    'debts' => true,
                    'networth' => 'advanced',
                    'investments' => true,
                    
                    // FITUR EKSKLUSIF
                    'export_data' => 'all',
                    'custom_categories' => true,
                    'recurring_transactions' => true,
                    'notifications' => 'premium',
                    'reports' => 'unlimited',
                    'budget_templates' => 'all',
                    'multi_currency' => true,
                    'scheduled_exports' => 'custom',
                    'api_access' => true,
                    'priority_support' => true,
                    'early_access' => true,
                    'financial_insights_ai' => true,
                    'anomaly_detection' => true,
                    'custom_reports' => true,
                ],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::create($plan);
        }
    }
}