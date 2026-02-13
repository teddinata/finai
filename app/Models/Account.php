<?php
// app/Models/Account.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = [
        'household_id',
        'name',
        'type',
        'account_number',
        'institution',
        'icon',
        'color',
        'initial_balance',
        'current_balance',
        'include_in_total',
        'is_active',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'initial_balance' => 'integer',
        'current_balance' => 'integer',
        'include_in_total' => 'boolean',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function transfersFrom(): HasMany
    {
        return $this->hasMany(Transfer::class, 'from_account_id');
    }

    public function transfersTo(): HasMany
    {
        return $this->hasMany(Transfer::class, 'to_account_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForHousehold($query, int $householdId)
    {
        return $query->where('household_id', $householdId);
    }

    public function scopeIncludedInTotal($query)
    {
        return $query->where('include_in_total', true);
    }

    // Helpers
    public function getFormattedBalance(): string
    {
        return 'Rp ' . number_format($this->current_balance , 0, ',', '.');
    }

    public function getFormattedInitialBalance(): string
    {
        return 'Rp ' . number_format($this->initial_balance , 0, ',', '.');
    }

    public function getTypeLabel(): string
    {
        return match($this->type) {
            'bank' => 'Bank Account',
            'cash' => 'Cash',
            'ewallet' => 'E-Wallet',
            'credit_card' => 'Credit Card',
            'investment' => 'Investment',
            'savings' => 'Savings',
            default => 'Other',
        };
    }

    /**
     * Update balance (called by transactions/transfers)
     */
    public function updateBalance(): void
    {
        $income = $this->transactions()
            ->where('type', 'income')
            ->sum('total');

        $expense = $this->transactions()
            ->where('type', 'expense')
            ->sum('total');

        $transfersIn = $this->transfersTo()->sum('amount');
        $transfersOut = $this->transfersFrom()->sum('amount');

        $this->current_balance = $this->initial_balance + $income - $expense + $transfersIn - $transfersOut;
        $this->save();
    }
}