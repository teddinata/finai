<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\Account;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use App\Models\Plan;
use App\Models\Subscription;

class TransactionChatTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $household;
    protected $account;
    protected $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->user = User::factory()->create(['household_id' => $this->household->id]);
        $this->account = Account::factory()->create([
            'household_id' => $this->household->id,
            'name' => 'BCA',
            'initial_balance' => 1000000,
            'current_balance' => 1000000
        ]);
        $this->category = Category::factory()->create([
            'name' => 'Makanan & Minuman',
            'is_default' => true
        ]);

        $this->actingAs($this->user);

        // Create Plan with features
        $plan = Plan::create([
            'name' => 'Premium',
            'slug' => 'premium',
            'type' => 'monthly',
            'price' => 100000,
            'features' => ['max_transactions_per_month' => -1, 'max_ai_scans_per_month' => -1],
            'is_active' => true,
        ]);

        // Create Subscription
        $subscription = Subscription::create([
            'household_id' => $this->household->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->household->update(['current_subscription_id' => $subscription->id]);

        $this->household->refresh();
    }

    public function test_chat_infers_merchant_and_category()
    {
        // Mock Gemini response for "beli bensin"
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => json_encode([
                                        'merchant' => 'SPBU',
                                        'items' => [['nama' => 'Bensin Motor', 'qty' => 1, 'harga_satuan' => 20000]],
                                        'total' => 20000,
                                        'category_id' => $this->category->id, // Use existing category for simplicity or create explicit one
                                        'type' => 'expense',
                                        'account_id' => $this->account->id
                                    ])]
                            ]
                        ]
                    ]
                ]
            ], 200),
        ]);

        $response = $this->postJson('/api/transactions/chat', [
            'message' => 'Beli bensin motor 20rb pake BCA'
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('transaction.merchant', 'SPBU')
            ->assertJsonPath('transaction.total', 20000);
    }

    public function test_chat_correctly_categorizes_salary_and_utilities()
    {
        // 1. Test Salary (Income)
        $gajiCategory = \App\Models\Category::create(['name' => 'Gaji', 'type' => 'income', 'icon' => 'money', 'color' => 'green', 'sort_order' => 1]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                                        'merchant' => 'Kantor',
                                        'total' => 5000000,
                                        'category_id' => $gajiCategory->id,
                                        'type' => 'income',
                                        'account_id' => $this->account->id,
                                        'items' => [['nama' => 'Gaji Bulanan', 'qty' => 1, 'harga_satuan' => 5000000]]
                                    ])]]]]]], 200)
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                                        'merchant' => 'PLN',
                                        'total' => 100000,
                                        'category_id' => $this->category->id,
                                        'type' => 'expense',
                                        'account_id' => $this->account->id,
                                        'items' => [['nama' => 'Token Listrik', 'qty' => 1, 'harga_satuan' => 100000]]
                                    ])]]]]]], 200),
        ]);

        // Test Gaji
        $response = $this->postJson('/api/transactions/chat', ['message' => 'Terima gaji 5 juta masuk BCA']);

        $response->assertStatus(201)
            ->assertJsonPath('transaction.type', 'income')
            ->assertJsonPath('transaction.category.name', 'Gaji');

        // Test Listrik
        $response = $this->postJson('/api/transactions/chat', ['message' => 'Beli token listrik 100rb pake BCA']);

        if ($response->status() !== 201) {
            dd($response->json());
        }

        $response->assertStatus(201)
            ->assertJsonPath('transaction.merchant', 'PLN')
            ->assertJsonPath('transaction.category.id', $this->category->id);
    }

    public function test_chat_creates_transaction_successfully()
    {
        // Mock Gemini Response
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'merchant' => 'Warung Tegal',
                                        'items' => [
                                            ['nama' => 'Nasi Rames', 'qty' => 1, 'harga_satuan' => 15000]
                                        ],
                                        'subtotal' => 15000,
                                        'diskon' => 0,
                                        'total' => 15000,
                                        'account_id' => $this->account->id,
                                        'type' => 'expense',
                                        'notes' => 'Beli makan siang via chat'
                                    ])
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $response = $this->postJson('/api/transactions/chat', [
            'message' => 'Beli nasi rames 15rb pake BCA id ' . $this->account->id
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('transaction.merchant', 'Warung Tegal')
            ->assertJsonPath('transaction.total', 15000)
            ->assertJsonPath('transaction.account.id', $this->account->id);

        $this->assertDatabaseHas('transactions', [
            'household_id' => $this->household->id,
            'merchant' => 'Warung Tegal',
            'total' => 15000,
            'account_id' => $this->account->id
        ]);

        // Check balance updated
        $this->assertDatabaseHas('accounts', [
            'id' => $this->account->id,
            'current_balance' => 985000 // 1000000 - 15000
        ]);
    }
}