<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'type',
        'price',
        'currency',
        'features',
        'description',
        'is_active',
        'is_popular',
        'sort_order',
    ];

    protected $casts = [
        'features' => 'array',
        'price' => 'integer',
        'is_active' => 'boolean',
        'is_popular' => 'boolean',
        'sort_order' => 'integer',
    ];

    // Relationships
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePopular($query)
    {
        return $query->where('is_popular', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    // Helper methods
    public function isFree(): bool
    {
        return $this->type === 'free';
    }

    public function isRecurring(): bool
    {
        return in_array($this->type, ['monthly', 'yearly']);
    }

    public function isLifetime(): bool
    {
        return $this->type === 'lifetime';
    }

    /**
     * Get feature value with default fallback
     */
    public function getFeature(string $key, $default = null)
    {
        return $this->features[$key] ?? $default;
    }

    /**
     * Check if feature is enabled (boolean check)
     */
    public function hasFeature(string $key): bool
    {
        $value = $this->features[$key] ?? false;

        // Handle different value types
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return !in_array($value, ['false', 'default', 'basic', 'limited']);
        }

        if (is_numeric($value)) {
            return $value !== 0;
        }

        return !empty($value);
    }

    /**
     * Check if feature allows unlimited (-1)
     */
    public function isUnlimited(string $key): bool
    {
        return ($this->features[$key] ?? 0) === -1;
    }

    /**
     * Get numeric limit for feature
     */
    public function getLimit(string $key): int
    {
        $value = $this->features[$key] ?? 0;
        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * Check if can invite members
     */
    public function canInviteMembers(): bool
    {
        return $this->getFeature('invite_members', false) === true;
    }

    /**
     * Check if has web access
     */
    public function hasWebAccess(): bool
    {
        return $this->getFeature('web_access', false) === true;
    }

    /**
     * Check if can access module
     */
    public function canAccessModule(string $module): bool
    {
        $value = $this->getFeature($module, false);

        if (is_bool($value)) {
            return $value;
        }

        // For string values like 'basic', 'advanced', etc.
        if (is_string($value)) {
            return !in_array($value, ['false', 'none']);
        }

        return false;
    }

    /**
     * Get module access level
     */
    public function getModuleLevel(string $module): ?string
    {
        $value = $this->getFeature($module);
        return is_string($value) ? $value : null;
    }

    /**
     * Get formatted price
     */
    public function getFormattedPrice(): string
    {
        if ($this->price === 0) {
            return 'Gratis';
        }

        if ($this->isLifetime()) {
            return 'Rp ' . number_format($this->price, 0, ',', '.') . ' (Lifetime)';
        }

        $monthly = 'Rp ' . number_format($this->price, 0, ',', '.');

        return match ($this->type) {
                'monthly' => $monthly . '/bulan',
                'yearly' => $monthly . '/tahun',
                default => $monthly,
            };
    }

    /**
     * Get daily cost for yearly plans
     */
    public function getDailyCost(): ?int
    {
        if ($this->type === 'yearly') {
            return (int)round($this->price / 365);
        }

        if ($this->type === 'lifetime') {
            // Assume 2 years for lifetime calculation
            return (int)round($this->price / 730);
        }

        return null;
    }

    /**
     * Get formatted daily cost
     */
    public function getFormattedDailyCost(): ?string
    {
        $daily = $this->getDailyCost();

        if (!$daily) {
            return null;
        }

        return 'Rp ' . number_format($daily, 0, ',', '.') . '/hari';
    }

    /**
     * Compare with another plan
     */
    public function isUpgradeFrom(Plan $otherPlan): bool
    {
        return $this->sort_order > $otherPlan->sort_order;
    }

    /**
     * Get upgrade incentive message
     */
    public function getUpgradeMessage(): ?string
    {
        return match ($this->slug) {
                'premium-free' => 'Mau pakai bareng pasangan? Upgrade ke Pertalite!',
                'pertalite' => 'Butuh web access & analytics? Upgrade ke Pertamax!',
                'pertamax' => 'Mau unlimited & AI insights? Upgrade ke Turbo!',
                default => null,
            };
    }
}