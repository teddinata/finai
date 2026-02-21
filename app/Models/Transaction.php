<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    protected $fillable = [
        'household_id',
        'created_by',
        'category_id',
        'account_id',
        'recurring_transaction_id',
        'investment_id',
        'type',
        'merchant',
        'tanggal',
        'subtotal',
        'diskon',
        'total',
        'metode_pembayaran',
        'source',
        'notes',
        'receipt_image',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'subtotal' => 'integer',
        'diskon' => 'integer',
        'total' => 'integer',
    ];

    // Relationships
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class , 'created_by');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function recurringTransaction(): BelongsTo
    {
        return $this->belongsTo(RecurringTransaction::class);
    }

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }

    public function savingsGoals()
    {
        return $this->belongsToMany(SavingsGoal::class , 'savings_goal_contributions')
            ->withPivot('amount')
            ->withTimestamps();
    }

    // Scopes
    // Add these scopes
    public function scopeIncome($query)
    {
        return $query->where('type', 'income');
    }

    public function scopeExpense($query)
    {
        return $query->where('type', 'expense');
    }

    public function isIncome(): bool
    {
        return $this->type === 'income';
    }

    public function isExpense(): bool
    {
        return $this->type === 'expense';
    }
    public function scopeForHousehold($query, int $householdId)
    {
        return $query->where('household_id', $householdId);
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('tanggal', $date);
    }

    public function scopeForMonth($query, int $year, int $month)
    {
        return $query->whereYear('tanggal', $year)
            ->whereMonth('tanggal', $month);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereYear('tanggal', now()->year)
            ->whereMonth('tanggal', now()->month);
    }

    public function scopeForCategory($query, int $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }

    public function scopeScanned($query)
    {
        return $query->where('source', 'scan');
    }

    public function scopeManual($query)
    {
        return $query->where('source', 'manual');
    }

    public function scopeRecent($query, int $limit = 10)
    {
        return $query->orderBy('tanggal', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit($limit);
    }

    // Helper methods
    public function isScanned(): bool
    {
        return $this->source === 'scan';
    }

    public function isManual(): bool
    {
        return $this->source === 'manual';
    }

    public function hasReceipt(): bool
    {
        return $this->receipt_image !== null;
    }

    public function hasDiscount(): bool
    {
        return $this->diskon > 0;
    }

    public function getFormattedTotal(): string
    {
        return 'Rp ' . number_format($this->total, 0, ',', '.');
    }

    public function getFormattedSubtotal(): string
    {
        return 'Rp ' . number_format($this->subtotal, 0, ',', '.');
    }

    public function getFormattedDiskon(): string
    {
        return 'Rp ' . number_format($this->diskon, 0, ',', '.');
    }

    public function getReceiptUrl(): ?string
    {
        if (!$this->receipt_image) {
            return null;
        }

        return asset('storage/' . $this->receipt_image);
    }

    // Tambahkan di app/Models/Transaction.php
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    protected static function boot()
    {
        parent::boot();

        // Update account balance when transaction changes
        static::created(function ($transaction) {
            if ($transaction->account_id) {
                $transaction->account->updateBalance();
            }
        });

        static::updated(function ($transaction) {
            if ($transaction->account_id) {
                $transaction->account->updateBalance();
            }
        });

        static::deleted(function ($transaction) {
            if ($transaction->account_id) {
                $transaction->account->updateBalance();
            }
        });

        static::deleting(function ($transaction) {
            // Delete all items when transaction is deleted
            $transaction->items()->delete();

            // Delete receipt image if exists
            if ($transaction->receipt_image && \Storage::exists($transaction->receipt_image)) {
                \Storage::delete($transaction->receipt_image);
            }
        });
    }

}