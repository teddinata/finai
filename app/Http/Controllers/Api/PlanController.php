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
                        return [
                            'id' => $plan->id,
                            'name' => $plan->name,
                            'slug' => $plan->slug,
                            'type' => $plan->type,
                            'price' => $plan->price,
                            'formatted_price' => $plan->getFormattedPrice(),
                            'currency' => $plan->currency,
                            'description' => $plan->description,
                            'features' => $plan->features,
                            'is_popular' => $plan->is_popular,
                        ];
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
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'type' => $plan->type,
                'price' => $plan->price,
                'formatted_price' => $plan->getFormattedPrice(),
                'currency' => $plan->currency,
                'description' => $plan->description,
                'features' => $plan->features,
                'is_popular' => $plan->is_popular,
            ],
        ]);
    }
}