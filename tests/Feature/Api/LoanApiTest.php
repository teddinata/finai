<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\CheckSubscription;
use App\Models\User;
use App\Models\Household;
use App\Models\Account;
use App\Models\Category;
use App\Models\Loan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanApiTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $household;
    protected $account;
    protected $incomeCategory;
    protected $expenseCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(CheckSubscription::class);

        $this->household = Household::factory()->create();
        $this->user = User::factory()->create([
            'household_id' => $this->household->id
        ]);

        $this->account = Account::factory()->create([
            'household_id' => $this->household->id,
            'current_balance' => 0,
            'initial_balance' => 0
        ]);

        $this->incomeCategory = Category::factory()->create([
            'household_id' => $this->household->id,
            'name' => 'Pendapatan Lainnya',
            'type' => 'income'
        ]);

        $this->expenseCategory = Category::factory()->create([
            'household_id' => null,
            'name' => 'Cicilan & Utang',
            'type' => 'expense'
        ]);
    }

    public function test_can_create_loan_and_disburse()
    {
        $payload = [
            'account_id' => $this->account->id,
            'name' => 'KPR Rumah',
            'principal_amount' => 100000000,
            'interest_amount' => 20000000,
            'tenor_months' => 120,
            'installment_amount' => 1000000,
            'start_date' => now()->toDateString(),
            'target_end_date' => now()->addMonths(120)->toDateString(),
            'next_payment_date' => now()->addMonth()->toDateString(),
            'create_disbursement_transaction' => true
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/loans', $payload);

        $response->assertStatus(201);

        $this->assertDatabaseHas('loans', [
            'name' => 'KPR Rumah',
            'total_amount' => 120000000,
            'paid_amount' => 0,
            'status' => 'active'
        ]);

        $this->assertDatabaseHas('transactions', [
            'type' => 'income',
            'subtotal' => 100000000,
            'loan_id' => $response->json('data.id')
        ]);

        // Ensure account balance increased via Transaction Observer
        $this->account->refresh();
        $this->assertEquals(100000000, $this->account->current_balance);
    }

    public function test_can_pay_loan_installment()
    {
        $loan = Loan::create([
            'household_id' => $this->household->id,
            'created_by' => $this->user->id,
            'account_id' => $this->account->id,
            'name' => 'Test Loan',
            'principal_amount' => 10000000,
            'interest_amount' => 2000000,
            'total_amount' => 12000000,
            'paid_amount' => 0,
            'initial_paid_amount' => 0,
            'tenor_months' => 12,
            'paid_periods' => 0,
            'initial_paid_periods' => 0,
            'installment_amount' => 1000000,
            'start_date' => now()->subMonth(),
            'target_end_date' => now()->addMonths(11),
            'next_payment_date' => now(),
            'status' => 'active'
        ]);

        $this->account->update(['current_balance' => 5000000]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/loans/{$loan->id}/pay", [
            'amount' => 1000000,
            'date' => now()->toDateString(),
            'account_id' => $this->account->id
        ]);

        $response->assertStatus(200);

        $loan->refresh();
        $this->assertEquals(1000000, $loan->paid_amount);
        $this->assertEquals(1, $loan->paid_periods);

        // Account balance decreased
        $this->account->refresh();
        $this->assertEquals(4000000, $this->account->current_balance);

        $this->assertDatabaseHas('transactions', [
            'loan_id' => $loan->id,
            'type' => 'expense',
            'subtotal' => 1000000
        ]);
    }

    public function test_auto_complete_loan_when_fully_paid()
    {
        $loan = Loan::create([
            'household_id' => $this->household->id,
            'created_by' => $this->user->id,
            'account_id' => $this->account->id,
            'name' => 'Short Loan',
            'principal_amount' => 1000000,
            'interest_amount' => 0,
            'total_amount' => 1000000,
            'paid_amount' => 500000,
            'initial_paid_amount' => 500000,
            'tenor_months' => 2,
            'paid_periods' => 1,
            'initial_paid_periods' => 1,
            'installment_amount' => 500000,
            'start_date' => now()->subMonth(),
            'target_end_date' => now(),
            'next_payment_date' => now(),
            'status' => 'active'
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/loans/{$loan->id}/pay", [
            'amount' => 500000,
            'date' => now()->toDateString(),
            'account_id' => $this->account->id
        ]);

        $response->assertStatus(200);

        $loan->refresh();
        $this->assertEquals(1000000, $loan->paid_amount);
        $this->assertEquals('paid_off', $loan->status);
    }
}