<?php
// app/Models/ParentCategory.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParentCategory extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'icon',
        'color',
        'description',
        'sort_order',
    ];

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function budgetLimits(): HasMany
    {
        return $this->hasMany(BudgetLimit::class);
    }

    /**
     * Get total spending for this parent category
     */
    public function getTotalSpending(int $householdId, string $startDate, string $endDate): int
    {
        return Transaction::whereHas('category', function($q) {
                $q->where('parent_category_id', $this->id);
            })
            ->where('household_id', $householdId)
            ->where('type', 'expense')
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->sum('total');
    }
}