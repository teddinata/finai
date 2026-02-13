<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    protected $fillable = [
        'subscription_id',
        'household_id',
        'user_id',
        'amount',
        'tax',
        'total',
        'currency',
        'payment_method',
        'payment_gateway_id',
        'snap_token',
        'status',
        'paid_at',
        'failed_at',
        'refunded_at',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'integer',
        'tax' => 'integer',
        'total' => 'integer',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'refunded_at' => 'datetime',
        'metadata' => 'array',
    ];

    // Relationships
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeRefunded($query)
    {
        return $query->where('status', 'refunded');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    // Helper methods
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isRefunded(): bool
    {
        return $this->status === 'refunded';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    public function markAsPaid(string $paymentGatewayId = null, array $metadata = []): bool
    {
        return $this->update([
            'status' => 'paid',
            'paid_at' => now(),
            'payment_gateway_id' => $paymentGatewayId ?? $this->payment_gateway_id,
            'metadata' => array_merge($this->metadata ?? [], $metadata),
        ]);
    }

    public function markAsFailed(array $metadata = []): bool
    {
        return $this->update([
            'status' => 'failed',
            'failed_at' => now(),
            'metadata' => array_merge($this->metadata ?? [], $metadata),
        ]);
    }

    public function markAsRefunded(array $metadata = []): bool
    {
        return $this->update([
            'status' => 'refunded',
            'refunded_at' => now(),
            'metadata' => array_merge($this->metadata ?? [], $metadata),
        ]);
    }

    public function getFormattedTotal(): string
    {
        return 'Rp ' . number_format($this->total , 0, ',', '.');
    }

    public function getFormattedAmount(): string
    {
        return 'Rp ' . number_format($this->amount , 0, ',', '.');
    }
}
