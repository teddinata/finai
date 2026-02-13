<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\RecurringTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessRecurringTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'recurring:process {--dry-run : Run without creating transactions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process recurring transactions and generate new transactions';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        $this->info('🔄 Processing recurring transactions...');
        $this->info('Dry run: ' . ($dryRun ? 'YES' : 'NO'));
        $this->newLine();

        // Get all due recurring transactions
        $recurring = RecurringTransaction::dueToday()
            ->with(['household', 'category', 'account'])
            ->get();

        if ($recurring->isEmpty()) {
            $this->info('✅ No recurring transactions due today.');
            return 0;
        }

        $this->info("Found {$recurring->count()} recurring transaction(s) to process:");
        $this->newLine();

        $processed = 0;
        $failed = 0;

        foreach ($recurring as $r) {
            $this->line("📋 {$r->name} ({$r->household->name})");
            $this->line("   Next occurrence: {$r->next_occurrence->format('Y-m-d')}");
            $this->line("   Amount: {$r->getFormattedAmount()}");

            if ($dryRun) {
                $this->line("   [DRY RUN] Would generate transaction");
                $processed++;
                continue;
            }

            DB::beginTransaction();
            try {
                $transaction = $r->generateTransaction();

                if ($transaction) {
                    $this->info("   ✅ Generated transaction ID: {$transaction->id}");
                    $processed++;
                    
                    // Log success
                    Log::info("Recurring transaction processed", [
                        'recurring_id' => $r->id,
                        'transaction_id' => $transaction->id,
                        'household_id' => $r->household_id,
                    ]);
                } else {
                    $this->error("   ❌ Failed to generate transaction");
                    $failed++;
                    
                    Log::warning("Recurring transaction failed to generate", [
                        'recurring_id' => $r->id,
                        'household_id' => $r->household_id,
                    ]);
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("   ❌ Error: {$e->getMessage()}");
                $failed++;
                
                Log::error("Recurring transaction processing error", [
                    'recurring_id' => $r->id,
                    'household_id' => $r->household_id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->newLine();
        }

        // Summary
        $this->newLine();
        $this->info('📊 Summary:');
        $this->table(
            ['Status', 'Count'],
            [
                ['Processed', $processed],
                ['Failed', $failed],
                ['Total', $recurring->count()],
            ]
        );

        if ($failed > 0) {
            return 1;
        }

        return 0;
    }
}