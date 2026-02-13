<?php
// app/Models/BudgetLimit.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLimit extends Model
{
    protected $fillable = [
        'household_id',
        'parent_category_id',
        'category_id',
        'limit_amount',
        'period_type',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'limit_amount' => 'integer',
        'is_active' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function parentCategory(): BelongsTo
    {
        return $this->belongsTo(ParentCategory::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function getFormattedLimit(): string
    {
        return 'Rp ' . number_format($this->limit_amount , 0, ',', '.');
    }

    /**
     * Get current spending for this budget limit
     */
    public function getCurrentSpending(): int
    {
        $query = Transaction::where('household_id', $this->household_id)
            ->where('type', 'expense')
            ->whereBetween('tanggal', [$this->start_date, $this->end_date ?? now()]);

        if ($this->category_id) {
            $query->where('category_id', $this->category_id);
        } elseif ($this->parent_category_id) {
            $query->whereHas('category', function($q) {
                $q->where('parent_category_id', $this->parent_category_id);
            });
        }

        return $query->sum('total');
    }

    /**
     * Get remaining budget
     */
    public function getRemainingBudget(): int
    {
        return $this->limit_amount - $this->getCurrentSpending();
    }

    /**
     * Get usage percentage
     */
    public function getUsagePercentage(): float
    {
        if ($this->limit_amount === 0) return 0;
        return round(($this->getCurrentSpending() / $this->limit_amount) * 100 , 1);
    }

    /**
     * Check if budget is exceeded
     */
    public function isExceeded(): bool
    {
        return $this->getCurrentSpending() > $this->limit_amount;
    }
}