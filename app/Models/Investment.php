<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Investment extends Model
{
    protected $fillable = [
        'household_id',
        'created_by',
        'account_id',
        'name',
        'symbol',
        'type',
        'quantity',
        'avg_buy_price',
        'initial_amount',
        'current_value',
        'current_price',
        'platform',
        'currency',
        'purchase_date',
        'notes',
        'icon',
        'color',
        'status',
        'last_updated_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:8',
        'avg_buy_price' => 'integer',
        'initial_amount' => 'integer',
        'current_value' => 'integer',
        'current_price' => 'decimal:8',
        'purchase_date' => 'date',
        'last_updated_at' => 'datetime',
    ];

    // Relationships
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InvestmentTransaction::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForHousehold($query, int $householdId)
    {
        return $query->where('household_id', $householdId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // Helpers
    public function getFormattedInitialAmount(): string
    {
        return 'Rp ' . number_format($this->initial_amount, 0, ',', '.');
    }

    public function getFormattedCurrentValue(): string
    {
        return 'Rp ' . number_format($this->current_value, 0, ',', '.');
    }

    public function getFormattedAvgBuyPrice(): string
    {
        return 'Rp ' . number_format($this->avg_buy_price, 0, ',', '.');
    }

    public function getProfitLoss(): int
    {
        return $this->current_value - $this->initial_amount;
    }

    public function getFormattedProfitLoss(): string
    {
        $pl = $this->getProfitLoss();
        $formatted = 'Rp ' . number_format(abs($pl), 0, ',', '.');
        return $pl >= 0 ? "+{$formatted}" : "-{$formatted}";
    }

    public function getROI(): float
    {
        if ($this->initial_amount === 0) return 0;
        return round((($this->current_value - $this->initial_amount) / $this->initial_amount) * 100, 2);
    }

    public function getFormattedROI(): string
    {
        $roi = $this->getROI();
        return ($roi >= 0 ? '+' : '') . number_format($roi, 2) . '%';
    }

    public function isProfit(): bool
    {
        return $this->current_value > $this->initial_amount;
    }

    public function isLoss(): bool
    {
        return $this->current_value < $this->initial_amount;
    }

    public function getTypeLabel(): string
    {
        return match($this->type) {
            'stocks' => 'Saham',
            'mutual_funds' => 'Reksa Dana',
            'bonds' => 'Obligasi',
            'crypto' => 'Cryptocurrency',
            'gold' => 'Emas',
            'property' => 'Properti',
            'deposit' => 'Deposito',
            default => 'Lainnya',
        };
    }

    public function updateCurrentValue(float $currentPrice): void
    {
        $this->update([
            'current_price' => $currentPrice,
            'current_value' => round($this->quantity * $currentPrice),
            'last_updated_at' => now(),
        ]);
    }

    public function addBuyTransaction(float $quantity, int $pricePerUnit, int $fee = 0, string $date = null): void
    {
        // Create investment transaction
        InvestmentTransaction::create([
            'investment_id' => $this->id,
            'type' => 'buy',
            'quantity' => $quantity,
            'price_per_unit' => $pricePerUnit,
            'total_amount' => ($quantity * $pricePerUnit) + $fee,
            'fee' => $fee,
            'transaction_date' => $date ?? now()->toDateString(),
        ]);

        // Recalculate averages
        $this->recalculate();
    }

    public function addSellTransaction(float $quantity, int $pricePerUnit, int $fee = 0, string $date = null): void
    {
        InvestmentTransaction::create([
            'investment_id' => $this->id,
            'type' => 'sell',
            'quantity' => $quantity,
            'price_per_unit' => $pricePerUnit,
            'total_amount' => ($quantity * $pricePerUnit) - $fee,
            'fee' => $fee,
            'transaction_date' => $date ?? now()->toDateString(),
        ]);

        $this->recalculate();
        
        // Auto-archive if fully sold
        if ($this->quantity <= 0) {
            $this->update(['status' => 'sold']);
        }
    }

    public function recalculate(): void
    {
        $buys = $this->transactions()->where('type', 'buy')->get();
        $sells = $this->transactions()->where('type', 'sell')->get();

        $totalBought = $buys->sum('quantity');
        $totalSold = $sells->sum('quantity');
        $quantity = $totalBought - $totalSold;

        $totalInvested = $buys->sum('total_amount');
        $totalReceived = $sells->sum('total_amount');

        $avgBuyPrice = $totalBought > 0 ? round($totalInvested / $totalBought) : 0;
        $initialAmount = $totalInvested - $totalReceived;

        $this->update([
            'quantity' => $quantity,
            'avg_buy_price' => $avgBuyPrice,
            'initial_amount' => max(0, $initialAmount),
        ]);

        // Update current value if price available
        if ($this->current_price) {
            $this->update([
                'current_value' => round($quantity * $this->current_price)
            ]);
        }
    }
}