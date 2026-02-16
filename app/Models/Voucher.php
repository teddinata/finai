<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voucher extends Model
{
    protected $fillable = [
        'code',
        'name',
        'type',
        'value',
        'max_discount_amount',
        'min_purchase_amount',
        'max_uses',
        'max_uses_per_household',
        'used_count',
        'applicable_plans',
        'valid_from',
        'valid_until',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'value' => 'integer',
        'max_discount_amount' => 'integer',
        'min_purchase_amount' => 'integer',
        'max_uses' => 'integer',
        'max_uses_per_household' => 'integer',
        'used_count' => 'integer',
        'applicable_plans' => 'array',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function usages(): HasMany
    {
        return $this->hasMany(VoucherUsage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class , 'created_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeValid($query)
    {
        return $query->where('valid_from', '<=', now())
            ->where(function ($q) {
            $q->whereNull('valid_until')
                ->orWhere('valid_until', '>=', now());
        });
    }

    // Helpers
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $now = now();
        if ($this->valid_from > $now) {
            return false;
        }

        if ($this->valid_until && $this->valid_until < $now) {
            return false;
        }

        if ($this->max_uses && $this->used_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    public function calculateDiscount(int $amount): int
    {
        if ($this->type === 'fixed') {
            return min($this->value, $amount);
        }

        // Percentage
        $discount = (int)round(($amount * $this->value) / 100);

        if ($this->max_discount_amount) {
            return min($discount, $this->max_discount_amount);
        }

        return $discount;
    }

    public function hasReachedMaxUsesForHousehold(int $householdId): bool
    {
        $count = $this->usages()->where('household_id', $householdId)->count();
        return $count >= $this->max_uses_per_household;
    }

    public function canBeUsedForPlan(int $planId): bool
    {
        if (empty($this->applicable_plans)) {
            return true;
        }

        return in_array($planId, $this->applicable_plans);
    }
}