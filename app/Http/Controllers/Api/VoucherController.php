<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VoucherService;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class VoucherController extends Controller
{
    protected $voucherService;

    public function __construct(VoucherService $voucherService)
    {
        $this->voucherService = $voucherService;
    }

    /**
     * Get all active and valid public vouchers
     */
    public function index(Request $request)
    {
        $query = \App\Models\Voucher::where('is_active', true)
            ->where(function ($q) {
            $q->whereNull('valid_until')
                ->orWhere('valid_until', '>', now());
        });

        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('code', 'like', "%{$request->search}%")
                    ->orWhere('name', 'like', "%{$request->search}%");
            });
        }

        $vouchers = $query->latest()->get();

        return response()->json(['vouchers' => $vouchers]);
    }

    /**
     * Validate voucher code and get discount details
     */
    public function validate(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'plan_id' => 'required|exists:plans,id',
            'billing_cycle' => 'nullable|in:monthly,yearly',
        ]);

        $user = Auth::user();
        $household = $user->household;
        $plan = Plan::findOrFail($request->plan_id);
        $billingCycle = $request->billing_cycle ?? 'monthly';

        try {
            // Use correct base price depending on billing cycle
            $amount = $billingCycle === 'yearly' && $plan->effective_yearly_price !== null
                ? $plan->effective_yearly_price
                : $plan->effective_price;

            $voucher = $this->voucherService->validate($request->code, $household->id, $plan->id, $amount);
            $discount = $this->voucherService->calculateDiscount($voucher, $amount);

            return response()->json([
                'valid' => true,
                'voucher' => [
                    'code' => $voucher->code,
                    'name' => $voucher->name,
                    'type' => $voucher->type,
                    'value' => $voucher->value,
                ],
                'billing_cycle' => $billingCycle,
                'base_amount' => $amount,
                'discount_amount' => $discount,
                'final_amount' => max(0, $amount - $discount),
            ]);

        }
        catch (ValidationException $e) {
            return response()->json([
                'valid' => false,
                'message' => $e->validator->errors()->first('voucher_code'),
            ], 422);
        }
        catch (\Exception $e) {
            return response()->json([
                'valid' => false,
                'message' => 'Invalid voucher',
            ], 422);
        }
    }
}