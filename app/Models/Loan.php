<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class Loan extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'created_by',
        'account_id',
        'name',
        'principal_amount',
        'interest_amount',
        'total_amount',
        'paid_amount',
        'initial_paid_amount',
        'tenor_months',
        'paid_periods',
        'initial_paid_periods',
        'installment_amount',
        'start_date',
        'target_end_date',
        'next_payment_date',
        'last_payment_date',
        'status',
        'reminder_enabled',
        'reminder_days',
    ];

    protected $casts = [
        'principal_amount' => 'integer',
        'interest_amount' => 'integer',
        'total_amount' => 'integer',
        'paid_amount' => 'integer',
        'tenor_months' => 'integer',
        'paid_periods' => 'integer',
        'installment_amount' => 'integer',
        'start_date' => 'date',
        'target_end_date' => 'date',
        'next_payment_date' => 'date',
        'last_payment_date' => 'date',
        'reminder_enabled' => 'boolean',
        'reminder_days' => 'integer',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class , 'created_by');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class , 'loan_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // Helpers
    public function recalculate(): void
    {
        // Get all expense transactions linked to this loan (excluding the initial disbursement which is income/ignored)
        $installments = $this->transactions()
            ->where('type', 'expense')
            ->orderBy('tanggal', 'asc')
            ->get();

        $totalPaid = $installments->sum('total');
        $periodsPaid = $installments->count();

        // Account for any initial imported legacy payments made before the system tracked them
        // In this architecture, 'paid_amount' naturally includes the dynamic sum + whatever imported base
        // But to keep it simple, we don't store a "base". We just store current 'paid_amount' dynamically from DB
        // Wait, if it's imported, where do we keep the initial?
        // Let's rely on standard dynamically evaluated transactions + a manual offset if needed.
        // Actually, the simplest way to support "imported existing loan" in standard accounting is
        // to seed an initial "Adjustment" or base `expense` transaction that covers the pre-paid amount.
        // For now, let's assume `paid_amount` and `paid_periods` on the model are absolute and updated via transactions.
        // If we want a separate field for baseline imported payments, we can add it later.

        $this->paid_amount = $this->initial_paid_amount + $totalPaid;
        $this->paid_periods = $this->initial_paid_periods + $periodsPaid;

        if ($installments->isNotEmpty()) {
            $lastPayment = $installments->last();
            $this->last_payment_date = $lastPayment->tanggal;
        }

        if ($this->paid_amount >= $this->total_amount) {
            $this->status = 'paid_off';
        }
        else {
            $this->status = 'active';
            // Simple next payment calculation (just add a month to last payment date or start date)
            $baseDate = $this->last_payment_date ?Carbon::parse($this->last_payment_date) : Carbon::parse($this->start_date);
            $this->next_payment_date = $baseDate->addMonth()->toDateString();
        }

        $this->save();
    }
}