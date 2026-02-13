<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SavingsGoal extends Model
{
    protected $fillable = [
        'household_id',
        'created_by',
        'name',
        'description',
        'target_amount',
        'current_amount',
        'deadline',
        'icon',
        'color',
        'status',
        'priority',
    ];

    protected $casts = [
        'target_amount' => 'integer',
        'current_amount' => 'integer',
        'deadline' => 'date',
    ];

    // Relationships
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(Transaction::class, 'savings_goal_contributions')
                    ->withPivot('amount')
                    ->withTimestamps();
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeForHousehold($query, int $householdId)
    {
        return $query->where('household_id', $householdId);
    }

    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    // Helpers
    public function getProgressPercentage(): float
    {
        if ($this->target_amount === 0) return 0;
        return min(100, round(($this->current_amount / $this->target_amount) * 100, 1));
    }

    public function getRemainingAmount(): int
    {
        return max(0, $this->target_amount - $this->current_amount);
    }

    public function getFormattedTarget(): string
    {
        return 'Rp ' . number_format($this->target_amount, 0, ',', '.');
    }

    public function getFormattedCurrent(): string
    {
        return 'Rp ' . number_format($this->current_amount, 0, ',', '.');
    }

    public function getFormattedRemaining(): string
    {
        return 'Rp ' . number_format($this->getRemainingAmount(), 0, ',', '.');
    }

    public function isCompleted(): bool
    {
        return $this->current_amount >= $this->target_amount;
    }

    public function isOverdue(): bool
    {
        return $this->deadline && $this->deadline->isPast() && !$this->isCompleted();
    }

    public function getDaysRemaining(): ?int
    {
        if (!$this->deadline) return null;
        return now()->diffInDays($this->deadline, false);
    }

    public function addContribution(Transaction $transaction, int $amount): void
    {
        // Link transaction to this goal
        $this->transactions()->attach($transaction->id, ['amount' => $amount]);
        
        // Update current amount
        $this->increment('current_amount', $amount);
        
        // Auto-complete if target reached
        if ($this->isCompleted() && $this->status === 'active') {
            $this->update(['status' => 'completed']);
        }
    }

    public function removeContribution(Transaction $transaction): void
    {
        $contribution = $this->transactions()->where('transaction_id', $transaction->id)->first();
        
        if ($contribution) {
            $amount = $contribution->pivot->amount;
            $this->transactions()->detach($transaction->id);
            $this->decrement('current_amount', $amount);
            
            // Revert to active if was completed
            if ($this->status === 'completed' && !$this->isCompleted()) {
                $this->update(['status' => 'active']);
            }
        }
    }

    public function recalculateAmount(): void
    {
        $total = $this->transactions()->sum('savings_goal_contributions.amount');
        $this->update(['current_amount' => $total]);
    }
}