<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckModuleAccess
{
    /**
     * Handle an incoming request.
     *
     * @param string $module Module name to check (budget, analytics, assets, debts, etc)
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();
        $household = $user->household;

        if (!$household) {
            return response()->json([
                'message' => 'No household found',
            ], 403);
        }

        $subscription = $household->currentSubscription;

        if (!$subscription || !$subscription->isActive()) {
            return response()->json([
                'message' => 'Akses terbatas. Silakan berlangganan untuk menggunakan fitur ini.',
                'module' => $module,
            ], 402);
        }

        // Check if plan can access this module
        if (!$household->canAccessModule($module)) {
            $plan = $subscription->plan;

            return response()->json([
                'message' => "Fitur '{$module}' tidak tersedia pada paket Anda saat ini. Silakan tingkatkan paket Anda untuk mengakses fitur ini.",
                'current_plan' => $plan->name,
                'required_plans' => $this->getRequiredPlans($module),
                'upgrade_message' => $household->getUpgradeSuggestion(),
                'action' => 'upgrade_plan',
            ], 403); // Forbidden
        }

        return $next($request);
    }

    /**
     * Get required plans for a module
     */
    private function getRequiredPlans(string $module): array
    {
        return match ($module) {
                'budget' => ['Pertalite', 'Pertamax', 'Turbo'],
                'analytics' => ['Pertalite', 'Pertamax', 'Turbo'],
                'assets' => ['Pertalite', 'Pertamax', 'Turbo'],
                'debts' => ['Pertamax', 'Turbo'],
                'networth' => ['Pertamax', 'Turbo'],
                'investments' => ['Turbo'],
                default => [],
            };
    }
}