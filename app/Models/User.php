<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
        'household_id',
        'role',
        'is_billing_owner',
        'active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_billing_owner' => 'boolean',
        'active' => 'boolean',
    ];

    // Relationships
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function createdHouseholds(): HasMany
    {
        return $this->hasMany(Household::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function createdInviteCodes(): HasMany
    {
        return $this->hasMany(InviteCode::class, 'created_by');
    }

    public function usedInviteCodes(): HasMany
    {
        return $this->hasMany(InviteCode::class, 'used_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'created_by');
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(UsageLog::class);
    }

    // Scopes
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function scopeAdmins($query)
    {
        return $query->where('role', 'admin');
    }
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeOwners($query)
    {
        return $query->where('role', 'owner');
    }

    public function scopeMembers($query)
    {
        return $query->where('role', 'member');
    }

    public function scopeBillingOwners($query)
    {
        return $query->where('is_billing_owner', true);
    }

    public function scopeVerified($query)
    {
        return $query->whereNotNull('email_verified_at');
    }

    public function scopeUnverified($query)
    {
        return $query->whereNull('email_verified_at');
    }

    // Helper methods
    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isMember(): bool
    {
        return $this->role === 'member';
    }

    public function isBillingOwner(): bool
    {
        return $this->is_billing_owner;
    }

    public function canManageHousehold(): bool
    {
        return $this->isOwner();
    }

    public function canManageBilling(): bool
    {
        return $this->isBillingOwner();
    }

    public function hasHousehold(): bool
    {
        return $this->household_id !== null;
    }

    public function isVerified(): bool
    {
        return $this->hasVerifiedEmail();
    }

    public function needsVerification(): bool
    {
        return !$this->hasVerifiedEmail();
    }

    /**
     * Send the email verification notification.
     */
    public function sendEmailVerificationNotification()
    {
        $this->notify(new \App\Notifications\CustomVerifyEmail);
    }
}