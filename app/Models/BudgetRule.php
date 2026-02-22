<?php
// app/Models/BudgetRule.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetRule extends Model
{
    protected $fillable = [
        'household_id',
        'name',
        'is_active',
        'allocations',
        'monthly_income_target',
        'notes',
    ];

    protected $casts = [
        'allocations' => 'array',
        'monthly_income_target' => 'integer',
        'is_active' => 'boolean',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function getFormattedIncomeTarget(): string
    {
        return 'Rp ' . number_format($this->monthly_income_target, 0, ',', '.');
    }

    /**
     * Calculate budget allocation for each parent category
     */
    public function getAllocationAmounts(): array
    {
        return $this->getAllocationAmountsDynamic($this->monthly_income_target);
    }

    /**
     * Calculate budget allocation dynamically based on actual income
     */
    public function getAllocationAmountsDynamic(int $totalIncome): array
    {
        $result = [];
        foreach ($this->allocations as $slug => $percentage) {
            $amount = round($totalIncome * ($percentage / 100));
            $result[$slug] = [
                'percentage' => $percentage,
                'amount' => $amount,
                'formatted_amount' => 'Rp ' . number_format($amount, 0, ',', '.'),
            ];
        }
        return $result;
    }
}