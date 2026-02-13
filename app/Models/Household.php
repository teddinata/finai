<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Household extends Model
{
    protected $fillable = [
        'name',
        'created_by',
        'current_subscription_id',
        'trial_used',
        'trial_started_at',
        'trial_ends_at',
    ];

    protected $casts = [
        'trial_used' => 'boolean',
        'trial_started_at' => 'datetime',
        'trial_ends_at' => 'datetime',
    ];

    // Relationships
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function currentSubscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'current_subscription_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    // Feature Access Methods

    /**
     * Get current plan
     */
    public function getCurrentPlan(): ?Plan
    {
        return $this->currentSubscription?->plan;
    }

    /**
     * Check if has active subscription
     */
    public function hasActiveSubscription(): bool
    {
        return $this->currentSubscription && $this->currentSubscription->isActive();
    }

    /**
     * Can access feature (boolean check)
     */
    public function canAccessFeature(string $feature): bool
    {
        $plan = $this->getCurrentPlan();
        
        if (!$plan) {
            return false;
        }
        
        return $plan->hasFeature($feature);
    }

    /**
     * Can access module
     */
    public function canAccessModule(string $module): bool
    {
        $plan = $this->getCurrentPlan();
        
        if (!$plan) {
            return false;
        }
        
        return $plan->canAccessModule($module);
    }

    /**
     * Get module access level
     */
    public function getModuleLevel(string $module): ?string
    {
        $plan = $this->getCurrentPlan();
        
        if (!$plan) {
            return null;
        }
        
        return $plan->getModuleLevel($module);
    }

    /**
     * Get feature limit
     */
    public function getFeatureLimit(string $feature): int
    {
        $plan = $this->getCurrentPlan();
        
        if (!$plan) {
            return 0;
        }
        
        return $plan->getLimit($feature);
    }

    /**
     * Check if feature is unlimited
     */
    public function isFeatureUnlimited(string $feature): bool
    {
        $plan = $this->getCurrentPlan();
        
        if (!$plan) {
            return false;
        }
        
        return $plan->isUnlimited($feature);
    }

    /**
     * Can invite members
     */
    public function canInviteMembers(): bool
    {
        $plan = $this->getCurrentPlan();
        
        if (!$plan) {
            return false;
        }
        
        // Check if invite feature is enabled
        if (!$plan->canInviteMembers()) {
            return false;
        }
        
        // Check if max users reached
        $maxUsers = $plan->getLimit('max_users');
        
        // -1 = unlimited
        if ($maxUsers === -1) {
            return true;
        }
        
        // Check current user count
        $currentUsers = $this->users()->count();
        
        return $currentUsers < $maxUsers;
    }

    /**
     * Has web access
     */
    public function hasWebAccess(): bool
    {
        $plan = $this->getCurrentPlan();
        return $plan ? $plan->hasWebAccess() : false;
    }

    /**
     * Check usage against limit
     */
    public function hasReachedLimit(string $feature, int $currentUsage): bool
    {
        if ($this->isFeatureUnlimited($feature)) {
            return false;
        }
        
        $limit = $this->getFeatureLimit($feature);
        return $currentUsage >= $limit;
    }

    /**
     * Get remaining quota
     */
    public function getRemainingQuota(string $feature, int $currentUsage): int
    {
        if ($this->isFeatureUnlimited($feature)) {
            return -1; // Unlimited
        }
        
        $limit = $this->getFeatureLimit($feature);
        return max(0, $limit - $currentUsage);
    }

    /**
     * Get usage percentage
     */
    public function getUsagePercentage(string $feature, int $currentUsage): float
    {
        if ($this->isFeatureUnlimited($feature)) {
            return 0;
        }
        
        $limit = $this->getFeatureLimit($feature);
        
        if ($limit === 0) {
            return 0;
        }
        
        return min(100, ($currentUsage / $limit) * 100);
    }

    /**
     * Get upgrade suggestion
     */
    public function getUpgradeSuggestion(): ?string
    {
        $plan = $this->getCurrentPlan();
        return $plan ? $plan->getUpgradeMessage() : null;
    }

    /**
     * Can upgrade to plan
     */
    public function canUpgradeTo(Plan $plan): bool
    {
        $currentPlan = $this->getCurrentPlan();
        
        if (!$currentPlan) {
            return true;
        }
        
        return $plan->isUpgradeFrom($currentPlan);
    }
}