<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    /**
     * Get all active plans
     */
    public function index()
    {
        $plans = Plan::active()
                    ->ordered()
                    ->get()
                    ->map(function ($plan) {
                        return $this->formatPlan($plan);
                    });

        return response()->json([
            'plans' => $plans,
        ]);
    }

    /**
     * Get specific plan
     */
    public function show(Plan $plan)
    {
        if (!$plan->is_active) {
            return response()->json([
                'message' => 'Plan not available',
            ], 404);
        }

        return response()->json([
            'plan' => $this->formatPlan($plan),
        ]);
    }

    /**
     * Format plan response with discount/promo fields
     */
    private function formatPlan(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'type' => $plan->type,
            'price' => $plan->price,
            'discount_price' => $plan->discount_price,
            'price_yearly' => $plan->price_yearly,
            'discount_price_yearly' => $plan->discount_price_yearly,
            'effective_price' => $plan->effective_price,
            'effective_yearly_price' => $plan->effective_yearly_price,
            'has_promo' => $plan->discount_price !== null && $plan->discount_price < $plan->price,
            'has_yearly_promo' => $plan->discount_price_yearly !== null 
                && $plan->price_yearly !== null 
                && $plan->discount_price_yearly < $plan->price_yearly,
            'formatted_price' => $plan->getFormattedPrice(),
            'currency' => $plan->currency,
            'description' => $plan->description,
            'features' => $plan->features,
            'is_popular' => $plan->is_popular,
        ];
    }
}