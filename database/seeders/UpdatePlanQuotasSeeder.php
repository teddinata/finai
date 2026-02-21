<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UpdatePlanQuotasSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $updates = [
            'premium-free' => [
                'max_transactions_per_month' => 30, // reduced from 50
                'max_ai_scans_per_month' => 5, // reduced from 2
                'max_accounts' => 1, // stays 1
                'storage_mb' => 30, // reduced from 50
            ],
            'pertalite' => [
                'max_transactions_per_month' => 100, // reduced from 300
                'max_ai_scans_per_month' => 50, // reduced from 50
                'max_accounts' => 2, // reduced from 5
                'storage_mb' => 100, // reduced from 200
            ],
            'pertamax' => [
                'max_transactions_per_month' => 200, // reduced from 1000
                'max_ai_scans_per_month' => 100, // reduced from 200
                'max_accounts' => 6, // reduced from 15
                'storage_mb' => 200, // reduced from 1000
            ]
        ];

        foreach ($updates as $slug => $newLimits) {
            $plan = \App\Models\Plan::where('slug', $slug)->first();
            if ($plan) {
                // we merge existing features with the new limit overrides
                $features = $plan->features ?? [];
                $updatedFeatures = array_merge($features, $newLimits);

                $plan->update(['features' => $updatedFeatures]);
                $this->command->info("Updated limits for plan: {$plan->name}");
            }
        }
    }
}