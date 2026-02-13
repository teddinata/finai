<?php
// app/Models/Transfer.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transfer extends Model
{
    protected $fillable = [
        'household_id',
        'from_account_id',
        'to_account_id',
        'created_by',
        'amount',
        'tanggal',
        'notes',
    ];

    protected $casts = [
        'amount' => 'integer',
        'tanggal' => 'date',
    ];

    // Relationships
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeForHousehold($query, int $householdId)
    {
        return $query->where('household_id', $householdId);
    }

    public function scopeForAccount($query, int $accountId)
    {
        return $query->where(function($q) use ($accountId) {
            $q->where('from_account_id', $accountId)
              ->orWhere('to_account_id', $accountId);
        });
    }

    // Helpers
    public function getFormattedAmount(): string
    {
        return 'Rp ' . number_format($this->amount , 0, ',', '.');
    }

    /**
     * Update both account balances
     */
    protected static function boot()
    {
        parent::boot();

        static::created(function ($transfer) {
            $transfer->fromAccount->updateBalance();
            $transfer->toAccount->updateBalance();
        });

        static::updated(function ($transfer) {
            $transfer->fromAccount->updateBalance();
            $transfer->toAccount->updateBalance();
        });

        static::deleted(function ($transfer) {
            $transfer->fromAccount->updateBalance();
            $transfer->toAccount->updateBalance();
        });
    }
}