<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class RecurringTransaction extends Model
{
    protected $fillable = [
        'household_id',
        'created_by',
        'category_id',
        'account_id',
        'name',
        'description',
        'type',
        'merchant',
        'amount',
        'frequency',
        'interval',
        'start_date',
        'end_date',
        'next_occurrence',
        'occurrences_count',
        'max_occurrences',
        'status',
        'auto_create',
        'send_notification',
        'notes',
    ];

    protected $casts = [
        'amount' => 'integer',
        'interval' => 'integer',
        'occurrences_count' => 'integer',
        'max_occurrences' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'next_occurrence' => 'date',
        'auto_create' => 'boolean',
        'send_notification' => 'boolean',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function generatedTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'recurring_transaction_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDueToday($query)
    {
        return $query->where('next_occurrence', '<=', now()->toDateString())
                     ->where('status', 'active');
    }

    public function scopeForHousehold($query, int $householdId)
    {
        return $query->where('household_id', $householdId);
    }

    // Helpers
    public function getFormattedAmount(): string
    {
        return 'Rp ' . number_format($this->amount, 0, ',', '.');
    }

    public function getFrequencyLabel(): string
    {
        $labels = [
            'daily' => 'Harian',
            'weekly' => 'Mingguan',
            'monthly' => 'Bulanan',
            'yearly' => 'Tahunan',
        ];
        
        $label = $labels[$this->frequency] ?? $this->frequency;
        
        if ($this->interval > 1) {
            return "Setiap {$this->interval} " . strtolower($label);
        }
        
        return $label;
    }

    public function isDue(): bool
    {
        return $this->next_occurrence <= now()->toDateString() && $this->status === 'active';
    }

    public function shouldStop(): bool
    {
        // Stop if end_date reached
        if ($this->end_date && $this->end_date->isPast()) {
            return true;
        }
        
        // Stop if max occurrences reached
        if ($this->max_occurrences && $this->occurrences_count >= $this->max_occurrences) {
            return true;
        }
        
        return false;
    }

    public function calculateNextOccurrence(): Carbon
    {
        $current = Carbon::parse($this->next_occurrence);
        
        return match($this->frequency) {
            'daily' => $current->addDays($this->interval),
            'weekly' => $current->addWeeks($this->interval),
            'monthly' => $current->addMonths($this->interval),
            'yearly' => $current->addYears($this->interval),
            default => $current->addMonth(),
        };
    }

    public function generateTransaction(): ?Transaction
    {
        if (!$this->auto_create || !$this->isDue()) {
            return null;
        }

        // Create transaction
        $transaction = Transaction::create([
            'household_id' => $this->household_id,
            'created_by' => $this->created_by,
            'recurring_transaction_id' => $this->id,
            'category_id' => $this->category_id,
            'account_id' => $this->account_id,
            'type' => $this->type,
            'merchant' => $this->merchant ?? $this->name,
            'tanggal' => $this->next_occurrence,
            'subtotal' => $this->amount,
            'diskon' => 0,
            'total' => $this->amount,
            'source' => 'recurring',
            'notes' => "Auto-generated from: {$this->name}",
        ]);

        // Update recurring transaction
        $this->increment('occurrences_count');
        
        if ($this->shouldStop()) {
            $this->update(['status' => 'completed']);
        } else {
            $this->update([
                'next_occurrence' => $this->calculateNextOccurrence()
            ]);
        }

        return $transaction;
    }

    public function pause(): bool
    {
        return $this->update(['status' => 'paused']);
    }

    public function resume(): bool
    {
        if ($this->status !== 'paused') {
            return false;
        }
        
        // Recalculate next occurrence if overdue
        if ($this->next_occurrence->isPast()) {
            $this->update([
                'next_occurrence' => $this->calculateNextOccurrence(),
                'status' => 'active'
            ]);
        } else {
            $this->update(['status' => 'active']);
        }
        
        return true;
    }

    public function cancel(): bool
    {
        return $this->update(['status' => 'cancelled']);
    }
}