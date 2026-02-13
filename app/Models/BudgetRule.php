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
        return 'Rp ' . number_format($this->monthly_income_target , 0, ',', '.');
    }

    /**
     * Calculate budget allocation for each parent category
     */
    public function getAllocationAmounts(): array
    {
        $result = [];
        foreach ($this->allocations as $slug => $percentage) {
            $result[$slug] = [
                'percentage' => $percentage,
                'amount' => round($this->monthly_income_target * ($percentage / 100)),
                'formatted_amount' => 'Rp ' . number_format(
                    ($this->monthly_income_target * ($percentage )) , 
                    0, ',', '.'
                ),
            ];
        }
        return $result;
    }
}