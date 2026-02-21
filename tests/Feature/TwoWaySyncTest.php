<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Household;
use App\Models\Category;
use App\Models\Account;
use App\Models\SavingsGoal;
use App\Models\Investment;
use App\Models\RecurringTransaction;
use App\Models\Transaction;

class TwoWaySyncTest extends TestCase
{
    use RefreshDatabase;

    private $user;
    private $household;
    private $account;
    private $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->user = User::factory()->create(['household_id' => $this->household->id]);

        $plan = \App\Models\Plan::create([
            'name' => 'Premium',
            'slug' => 'premium',
            'price' => 10000,
            'type' => 'monthly',
            'features' => [
                'max_transactions_per_month' => -1,
            ],
        ]);

        $subscription = \App\Models\Subscription::create([
            'household_id' => $this->household->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->household->update(['current_subscription_id' => $subscription->id]);

        $this->account = Account::factory()->create(['household_id' => $this->household->id, 'current_balance' => 1000000]);
        $this->category = Category::factory()->create(['household_id' => $this->household->id, 'type' => 'expense']);
    }

    public function test_savings_goal_contribution_creates_transaction()
    {
        $goal = SavingsGoal::create([
            'household_id' => $this->household->id,
            'created_by' => $this->user->id,
            'name' => 'Auto Car',
            'target_amount' => 500000,
            'current_amount' => 0
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/savings-goals/{$goal->id}/contribute", [
            'amount' => 100000,
            'account_id' => $this->account->id,
            'tanggal' => now()->toDateString(),
            'notes' => 'Testing Auto Sync'
        ]);

        $response->assertStatus(200);
        $this->assertEquals(100000, $goal->fresh()->current_amount);

        $this->assertDatabaseHas('transactions', [
            'household_id' => $this->household->id,
            'subtotal' => 100000,
            'type' => 'expense',
            'notes' => 'Testing Auto Sync'
        ]);

        $transaction = Transaction::where('notes', 'Testing Auto Sync')->first();
        $this->assertDatabaseHas('savings_goal_contributions', [
            'savings_goal_id' => $goal->id,
            'transaction_id' => $transaction->id,
            'amount' => 100000,
        ]);
    }

    public function test_transaction_creation_updates_savings_goal()
    {
        $goal = SavingsGoal::create([
            'household_id' => $this->household->id,
            'created_by' => $this->user->id,
            'name' => 'Auto Car',
            'target_amount' => 500000,
            'current_amount' => 0
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/transactions", [
            'type' => 'expense',
            'merchant' => 'My Saving',
            'tanggal' => now()->toDateString(),
            'category_id' => $this->category->id,
            'account_id' => $this->account->id,
            'total' => 150000,
            'notes' => 'Testing Auto Sync 2',
            'savings_goal_id' => $goal->id,
        ]);

        $response->dump();
        $response->assertStatus(201);
        $this->assertEquals(150000, $goal->fresh()->current_amount);

        $transactionId = $response->json('transaction.id');
        $this->assertDatabaseHas('savings_goal_contributions', [
            'savings_goal_id' => $goal->id,
            'transaction_id' => $transactionId,
            'amount' => 150000,
        ]);
    }

    public function test_deleting_transaction_rolls_back_savings_goal()
    {
        $goal = SavingsGoal::create([
            'household_id' => $this->household->id,
            'created_by' => $this->user->id,
            'name' => 'Auto Car',
            'target_amount' => 500000,
            'current_amount' => 100000
        ]);

        $transaction = Transaction::create([
            'household_id' => $this->household->id,
            'created_by' => $this->user->id,
            'category_id' => $this->category->id,
            'account_id' => $this->account->id,
            'type' => 'expense',
            'merchant' => 'Nabung',
            'tanggal' => now()->toDateString(),
            'subtotal' => 100000,
            'diskon' => 0,
            'total' => 100000,
        ]);

        $goal->addContribution($transaction, 100000);
        $this->assertEquals(200000, $goal->fresh()->current_amount);

        $response = $this->actingAs($this->user)->deleteJson("/api/transactions/{$transaction->id}");
        $response->assertStatus(200);

        $this->assertEquals(100000, $goal->fresh()->current_amount);
        $this->assertDatabaseMissing('savings_goal_contributions', [
            'transaction_id' => $transaction->id,
        ]);
    }
}