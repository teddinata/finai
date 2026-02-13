<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'household_id',
        'type',  // ← Add this
        'name',
        'icon',
        'color',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
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

    public function parentCategory(): BelongsTo
    {
        return $this->belongsTo(ParentCategory::class);
    }

    public function budgetLimits(): HasMany
    {
        return $this->hasMany(BudgetLimit::class);
    }


    // Scopes
    // Add scopes
    public function scopeIncome($query)
    {
        return $query->whereIn('type', ['income', 'both']);
    }

    public function scopeExpense($query)
    {
        return $query->whereIn('type', ['expense', 'both']);
    }

    public function scopeByType($query, $type)
    {
        if ($type === 'both') {
            return $query;
        }
        return $query->where(function($q) use ($type) {
            $q->where('type', $type)->orWhere('type', 'both');
        });
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeCustom($query)
    {
        return $query->where('is_default', false);
    }

    public function scopeGlobal($query)
    {
        return $query->whereNull('household_id');
    }

    public function scopeForHousehold($query, int $householdId)
    {
        return $query->where(function($q) use ($householdId) {
            $q->whereNull('household_id')
              ->orWhere('household_id', $householdId);
        });
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    // Helper methods
    public function isDefault(): bool
    {
        return $this->is_default;
    }

    public function isGlobal(): bool
    {
        return $this->household_id === null;
    }

    public function isCustom(): bool
    {
        return !$this->is_default && $this->household_id !== null;
    }

    public static function getAvailableForHousehold(int $householdId)
    {
        return static::forHousehold($householdId)
                    ->ordered()
                    ->get();
    }

    public function getTotalTransactions(): int
    {
        return $this->transactions()->count();
    }

    public function getTotalAmount(): int
    {
        return $this->transactions()->sum('total');
    }
}
