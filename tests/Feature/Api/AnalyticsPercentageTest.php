<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\CheckModuleAccess;
use App\Http\Middleware\CheckSubscription;
use App\Models\Account;
use App\Models\Category;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Frontend memakai nilai percentage langsung sebagai lebar bar CSS dan
 * mencetaknya dengan tanda %. Jadi backend harus mengirim 30 untuk 30%,
 * bukan rasio 0,3.
 */
class AnalyticsPercentageTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;
    protected User $user;
    protected Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([CheckSubscription::class, CheckModuleAccess::class]);

        $this->household = Household::factory()->create();
        $this->user = User::factory()->create(['household_id' => $this->household->id]);
        $this->account = Account::factory()->create([
            'household_id' => $this->household->id,
            'initial_balance' => 0,
            'current_balance' => 0,
        ]);

        $this->actingAs($this->user);
    }

    /** JSON mengembalikan int untuk nilai bulat, jadi bandingkan secara numerik. */
    private function assertPercent(float $expected, $actual): void
    {
        $this->assertIsNumeric($actual);
        $this->assertEqualsWithDelta($expected, (float) $actual, 0.05);
    }

    private function expense(string $merchant, int $rupiah, string $tanggal, ?Category $category = null): void
    {
        Transaction::create([
            'household_id' => $this->household->id,
            'created_by' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => $category?->id,
            'type' => 'expense',
            'merchant' => $merchant,
            'tanggal' => $tanggal,
            'subtotal' => $rupiah,
            'diskon' => 0,
            'total' => $rupiah,
            'source' => 'manual',
        ]);
    }

    public function test_top_category_percentage_is_expressed_in_percent(): void
    {
        $makan = Category::factory()->create(['name' => 'Makan', 'type' => 'expense']);
        $transport = Category::factory()->create(['name' => 'Transport', 'type' => 'expense']);

        $today = now()->startOfMonth()->addDays(2)->toDateString();

        // 750rb dari total 1jt = 75%
        $this->expense('Warteg', 750_000, $today, $makan);
        $this->expense('Gojek', 250_000, $today, $transport);

        $response = $this->getJson('/api/analytics/summary');

        $response->assertStatus(200);
        $this->assertPercent(75, $response->json('top_categories.0.percentage'));
        $this->assertPercent(25, $response->json('top_categories.1.percentage'));
    }

    public function test_top_merchant_percentage_is_expressed_in_percent(): void
    {
        $today = now()->startOfMonth()->addDays(2)->toDateString();

        $this->expense('Tokopedia', 400_000, $today);
        $this->expense('Shopee', 100_000, $today);

        $response = $this->getJson('/api/analytics/summary');

        $response->assertStatus(200);
        $this->assertPercent(80, $response->json('top_merchants.0.percentage'));
        $this->assertPercent(20, $response->json('top_merchants.1.percentage'));
    }

    public function test_small_share_is_not_rounded_away(): void
    {
        $besar = Category::factory()->create(['name' => 'Besar', 'type' => 'expense']);
        $kecil = Category::factory()->create(['name' => 'Kecil', 'type' => 'expense']);

        $today = now()->startOfMonth()->addDays(2)->toDateString();

        // 3% — dengan rumus rasio lama, round(0.03, 1) menghasilkan 0.
        $this->expense('Sewa', 970_000, $today, $besar);
        $this->expense('Parkir', 30_000, $today, $kecil);

        $response = $this->getJson('/api/analytics/summary');

        $response->assertStatus(200);
        $this->assertPercent(3, $response->json('top_categories.1.percentage'));
    }

    public function test_spending_change_percentage_is_expressed_in_percent(): void
    {
        $start = now()->startOfMonth();

        // Periode berjalan 150rb, periode sebelumnya 100rb → naik 50%.
        $this->expense('Bulan ini', 150_000, $start->copy()->addDays(1)->toDateString());
        $this->expense('Sebelumnya', 100_000, $start->copy()->subDays(5)->toDateString());

        $response = $this->getJson('/api/analytics/summary?' . http_build_query([
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(9)->toDateString(),
        ]));

        $response->assertStatus(200);
        $this->assertPercent(50, $response->json('summary.spending_change_percentage'));
    }

    public function test_by_category_percentage_is_expressed_in_percent(): void
    {
        $makan = Category::factory()->create(['name' => 'Makan', 'type' => 'expense']);
        $transport = Category::factory()->create(['name' => 'Transport', 'type' => 'expense']);

        $today = now()->startOfMonth()->addDays(2)->toDateString();

        $this->expense('Warteg', 600_000, $today, $makan);
        $this->expense('Gojek', 400_000, $today, $transport);

        $response = $this->getJson('/api/analytics/by-category');

        $response->assertStatus(200);
        $this->assertPercent(60, $response->json('categories.0.percentage'));
        $this->assertPercent(40, $response->json('categories.1.percentage'));
    }
}
