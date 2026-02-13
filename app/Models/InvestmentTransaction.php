<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentTransaction extends Model
{
    protected $fillable = [
        'investment_id',
        'type',
        'quantity',
        'price_per_unit',
        'total_amount',
        'fee',
        'transaction_date',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:8',
        'price_per_unit' => 'integer',
        'total_amount' => 'integer',
        'fee' => 'integer',
        'transaction_date' => 'date',
    ];

    // Relationships
    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }

    // Helpers
    public function getFormattedTotal(): string
    {
        return 'Rp ' . number_format($this->total_amount, 0, ',', '.');
    }

    public function getFormattedPricePerUnit(): string
    {
        return 'Rp ' . number_format($this->price_per_unit, 0, ',', '.');
    }

    public function getFormattedFee(): string
    {
        return 'Rp ' . number_format($this->fee, 0, ',', '.');
    }

    public function getTypeLabel(): string
    {
        return match($this->type) {
            'buy' => 'Beli',
            'sell' => 'Jual',
            'dividend' => 'Dividen',
            'fee' => 'Biaya',
            default => $this->type,
        };
    }

    public function getTypeBadgeColor(): string
    {
        return match($this->type) {
            'buy' => 'blue',
            'sell' => 'green',
            'dividend' => 'purple',
            'fee' => 'red',
            default => 'gray',
        };
    }
}