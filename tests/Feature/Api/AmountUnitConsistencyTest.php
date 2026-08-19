<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Category;
use App\Models\Household;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Semua kolom uang disimpan sebagai rupiah utuh — satuan yang sama dengan
 * nominal yang dikirim ke payment gateway. Test ini mengunci agar tidak ada
 * jalur masuk yang diam-diam menskala nilainya.
 */
class AmountUnitConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /** Rp 50.000 sebagaimana tertulis di struk. */
    private const RUPIAH = 50_000;

    protected Household $household;
    protected User $user;
    protected Account $account;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->user = User::factory()->create(['household_id' => $this->household->id]);
        $this->account = Account::factory()->create([
            'household_id' => $this->household->id,
            'name' => 'BCA',
            'initial_balance' => 10_000_000,
            'current_balance' => 10_000_000,
        ]);
        $this->category = Category::factory()->create([
            'name' => 'Makanan & Minuman',
            'is_default' => true,
        ]);

        $plan = Plan::create([
            'name' => 'Premium',
            'slug' => 'premium',
            'type' => 'monthly',
            'price' => 100000,
            'features' => ['max_transactions_per_month' => -1, 'max_ai_scans_per_month' => -1],
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'household_id' => $this->household->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->household->update(['current_subscription_id' => $subscription->id]);
        $this->household->refresh();

        $this->actingAs($this->user);
    }

    private function fakeGemini(array $payload): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode($payload)]]]],
                ],
            ], 200),
        ]);
    }

    public function test_chat_stores_amount_unscaled(): void
    {
        $this->fakeGemini([
            'merchant' => 'Warteg Bahari',
            'total' => self::RUPIAH,
            'subtotal' => self::RUPIAH,
            'diskon' => 0,
            'category_id' => $this->category->id,
            'type' => 'expense',
            'account_id' => $this->account->id,
            'items' => [['nama' => 'Nasi rames', 'qty' => 1, 'harga_satuan' => self::RUPIAH]],
        ]);

        $response = $this->postJson('/api/transactions/chat', ['message' => 'nasi rames 50rb']);

        $response->assertStatus(201)
            ->assertJsonPath('transaction.total', self::RUPIAH)
            ->assertJsonPath('transaction.subtotal', self::RUPIAH)
            ->assertJsonPath('transaction.items.0.harga_satuan', self::RUPIAH);
    }

    public function test_scan_stores_amount_unscaled(): void
    {
        $this->fakeGemini([
            'merchant' => 'Indomaret',
            'total' => self::RUPIAH,
            'subtotal' => self::RUPIAH,
            'diskon' => 0,
            'tanggal' => now()->toDateString(),
            'metode_pembayaran' => 'cash',
            'items' => [['nama' => 'Air mineral', 'qty' => 2, 'harga_satuan' => 25_000]],
        ]);

        $response = $this->postJson('/api/transactions/scan', [
            'image' => UploadedFile::fake()->image('struk.jpg'),
            'account_id' => $this->account->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('transaction.total', self::RUPIAH)
            ->assertJsonPath('transaction.items.0.harga_satuan', 25_000)
            // qty 2 x Rp 25.000 = Rp 50.000
            ->assertJsonPath('transaction.items.0.harga_total', self::RUPIAH);
    }

    public function test_manual_and_ai_entry_of_same_receipt_produce_equal_totals(): void
    {
        $manual = $this->postJson('/api/transactions', [
            'account_id' => $this->account->id,
            'category_id' => $this->category->id,
            'type' => 'expense',
            'merchant' => 'Warteg Bahari',
            'tanggal' => now()->toDateString(),
            'total' => self::RUPIAH,
        ]);
        $manual->assertStatus(201);

        $this->fakeGemini([
            'merchant' => 'Warteg Bahari',
            'total' => self::RUPIAH,
            'subtotal' => self::RUPIAH,
            'diskon' => 0,
            'category_id' => $this->category->id,
            'type' => 'expense',
            'account_id' => $this->account->id,
            'items' => [],
        ]);
        $this->postJson('/api/transactions/chat', ['message' => 'warteg 50rb'])->assertStatus(201);

        $totals = Transaction::where('household_id', $this->household->id)->pluck('total', 'source');

        $this->assertSame(self::RUPIAH, (int) $totals['manual']);
        $this->assertSame(self::RUPIAH, (int) $totals['chat']);
    }

    public function test_account_balance_uses_same_unit_as_transaction(): void
    {
        $this->fakeGemini([
            'merchant' => 'Indomaret',
            'total' => self::RUPIAH,
            'subtotal' => self::RUPIAH,
            'diskon' => 0,
            'category_id' => $this->category->id,
            'type' => 'expense',
            'account_id' => $this->account->id,
            'items' => [],
        ]);

        $this->postJson('/api/transactions/chat', ['message' => 'belanja 50rb'])->assertStatus(201);

        $this->assertSame(
            10_000_000 - self::RUPIAH,
            (int) $this->account->fresh()->current_balance
        );
    }

    public function test_formatted_helper_matches_stored_value(): void
    {
        $transaction = Transaction::create([
            'household_id' => $this->household->id,
            'created_by' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => $this->category->id,
            'type' => 'expense',
            'merchant' => 'Indomaret',
            'tanggal' => now()->toDateString(),
            'subtotal' => 192_945,
            'diskon' => 0,
            'total' => 192_945,
            'source' => 'scan',
        ]);

        // Helper backend tidak menskala, jadi harus sama dengan nilai tersimpan.
        $this->assertSame('Rp 192.945', $transaction->getFormattedTotal());
    }

    public function test_net_worth_uses_same_unit_as_account_balance(): void
    {
        $snapshot = app(\App\Services\NetWorthService::class)->snapshot($this->household->id);

        $this->assertSame(10_000_000, $snapshot['assets']['cash']);
        $this->assertSame(10_000_000, $snapshot['net_worth']);
    }
}
