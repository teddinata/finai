<?php

namespace App\Services;

use App\Models\Voucher;
use App\Models\Payment;
use App\Models\VoucherUsage;
use App\Models\Plan;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class VoucherService
{
    /**
     * Validate voucher for a specific purchase
     */
    public function validate(string $code, int $householdId, int $planId, int $amount): Voucher
    {
        $voucher = Voucher::where('code', $code)->first();

        if (!$voucher) {
            throw ValidationException::withMessages([
                'voucher_code' => ['Voucher code not found'],
            ]);
        }

        if (!$voucher->isValid()) {
            throw ValidationException::withMessages([
                'voucher_code' => ['Voucher is invalid or expired'],
            ]);
        }

        if ($voucher->min_purchase_amount > $amount) {
            throw ValidationException::withMessages([
                'voucher_code' => ['Minimum purchase amount not met'],
            ]);
        }

        if (!$voucher->canBeUsedForPlan($planId)) {
            throw ValidationException::withMessages([
                'voucher_code' => ['Voucher cannot be used for this plan'],
            ]);
        }

        if ($voucher->hasReachedMaxUsesForHousehold($householdId)) {
            throw ValidationException::withMessages([
                'voucher_code' => ['You have reached the usage limit for this voucher'],
            ]);
        }

        return $voucher;
    }

    /**
     * Calculate discount amount
     */
    public function calculateDiscount(Voucher $voucher, int $amount): int
    {
        return $voucher->calculateDiscount($amount);
    }

    /**
     * Apply voucher to payment
     */
    public function apply(Voucher $voucher, Payment $payment): VoucherUsage
    {
        // Don't apply if already applied
        $existingUsage = VoucherUsage::where('payment_id', $payment->id)->first();
        if ($existingUsage) {
            return $existingUsage;
        }

        // Lock voucher row to prevent race conditions on usage count
        // Note: Transaction is handled by the caller (PaymentController)
        $voucher = Voucher::lockForUpdate()->find($voucher->id);

        if ($voucher->max_uses && $voucher->used_count >= $voucher->max_uses) {
            throw ValidationException::withMessages([
                'voucher_code' => ['Voucher usage limit reached'],
            ]);
        }

        $discountAmount = $this->calculateDiscount($voucher, $payment->original_amount ?? $payment->amount);

        // Update voucher usage count
        $voucher->increment('used_count');

        // Create usage record
        $usage = VoucherUsage::create([
            'voucher_id' => $voucher->id,
            'household_id' => $payment->household_id,
            'payment_id' => $payment->id,
            'discount_amount' => $discountAmount,
        ]);

        return $usage;
    }

    /**
     * Reverse voucher usage (e.g. on payment cancellation)
     */
    public function reverse(Payment $payment): void
    {
        if (!$payment->voucher_id) {
            return;
        }

        $usage = VoucherUsage::where('payment_id', $payment->id)->first();

        if ($usage) {
            // Decrement usage count
            $usage->voucher->decrement('used_count');

            // Delete usage record or mark as reversed? 
            // Better to delete or move to a "reversed_usages" table if audit is needed.
            // For now, let's just delete it to allow re-use if limit wasn't reached globally
            $usage->delete();
        }
    }
}