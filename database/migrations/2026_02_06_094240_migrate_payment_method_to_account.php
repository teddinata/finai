<?php
// database/migrations/2026_02_07_100003_migrate_payment_method_to_account.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Transaction;
use App\Models\Account;

return new class extends Migration
{
    public function up(): void
    {
        // Create default accounts for each household based on existing payment methods
        $households = \App\Models\Household::all();
        
        foreach ($households as $household) {
            $paymentMethods = Transaction::where('household_id', $household->id)
                ->distinct()
                ->pluck('metode_pembayaran');
            
            $accountMapping = [];
            
            foreach ($paymentMethods as $method) {
                $accountData = $this->getAccountDataFromPaymentMethod($method);
                
                $account = Account::create([
                    'household_id' => $household->id,
                    'name' => $accountData['name'],
                    'type' => $accountData['type'],
                    'icon' => $accountData['icon'],
                    'color' => $accountData['color'],
                    'initial_balance' => 0,
                    'current_balance' => 0,
                    'include_in_total' => true,
                    'is_active' => true,
                ]);
                
                $accountMapping[$method] = $account->id;
            }
            
            // Update existing transactions
            foreach ($accountMapping as $method => $accountId) {
                Transaction::where('household_id', $household->id)
                    ->where('metode_pembayaran', $method)
                    ->update(['account_id' => $accountId]);
            }
        }
        
        // Make metode_pembayaran nullable (don't drop for backward compatibility)
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('metode_pembayaran')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Revert account_id back to metode_pembayaran
        Schema::table('transactions', function (Blueprint $table) {
            $table->enum('metode_pembayaran', [
                'cash', 'transfer', 'kartu_kredit', 'kartu_debit', 'ewallet', 'other'
            ])->default('cash')->change();
        });
    }
    
    private function getAccountDataFromPaymentMethod(string $method): array
    {
        return match($method) {
            'cash' => [
                'name' => 'Cash',
                'type' => 'cash',
                'icon' => '💵',
                'color' => '#10B981'
            ],
            'transfer' => [
                'name' => 'Bank Account',
                'type' => 'bank',
                'icon' => '🏦',
                'color' => '#3B82F6'
            ],
            'kartu_kredit' => [
                'name' => 'Credit Card',
                'type' => 'credit_card',
                'icon' => '💳',
                'color' => '#EF4444'
            ],
            'kartu_debit' => [
                'name' => 'Debit Card',
                'type' => 'bank',
                'icon' => '💳',
                'color' => '#8B5CF6'
            ],
            'ewallet' => [
                'name' => 'E-Wallet',
                'type' => 'ewallet',
                'icon' => '📱',
                'color' => '#F59E0B'
            ],
            default => [
                'name' => 'Other',
                'type' => 'other',
                'icon' => '💰',
                'color' => '#6B7280'
            ],
        };
    }
};