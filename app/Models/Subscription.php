<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $fillable = [
        'household_id',
        'plan_id',
        'status',
        'started_at',
        'expires_at',
        'canceled_at',
        'trial_ends_at',
        'auto_renew',
        'cancellation_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'canceled_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'auto_renew' => 'boolean',
    ];

    // Relationships
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeTrial($query)
    {
        return $query->where('status', 'trial');
    }

    public function scopeCanceled($query)
    {
        return $query->where('status', 'canceled');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    public function scopeExpiring($query)
    {
        return $query->where('expires_at', '<=', now()->addDays(7))
                    ->whereIn('status', ['active', 'trial']);
    }

    // Helper methods
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isTrial(): bool
    {
        return $this->status === 'trial';
    }

    public function isCanceled(): bool
    {
        return $this->status === 'canceled';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    public function isPastDue(): bool
    {
        return $this->status === 'past_due';
    }

    public function isExpiring(): bool
    {
        return $this->expires_at && $this->expires_at->lte(now()->addDays(7));
    }

    public function daysUntilExpiry(): ?int
    {
        if (!$this->expires_at) {
            return null;
        }

        return now()->diffInDays($this->expires_at, false);
    }

    public function cancel(string $reason = null): bool
    {
        $this->update([
            'status' => 'canceled',
            'canceled_at' => now(),
            'cancellation_reason' => $reason,
            'auto_renew' => false,
        ]);

        return true;
    }

    public function renew(): bool
    {
        if (!$this->auto_renew) {
            return false;
        }

        // Logic for renewal based on plan type
        $expiresAt = match($this->plan->type) {
            'monthly' => now()->addMonth(),
            'yearly' => now()->addYear(),
            'lifetime' => null,
            default => $this->expires_at,
        };

        $this->update([
            'expires_at' => $expiresAt,
            'status' => 'active',
        ]);

        return true;
    }
}
