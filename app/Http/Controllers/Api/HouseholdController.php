<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\InviteCode;
use App\Models\User;
use Illuminate\Http\Request;

class HouseholdController extends Controller
{
    /**
     * Get household info
     */
    public function show(Request $request)
    {
        $household = $request->user()->household()
                            ->with(['users', 'currentSubscription.plan'])
                            ->first();

        if (!$household) {
            return response()->json([
                'success' => false,
                'message' => 'No household found'
            ], 404);
        }

        // ✅ FIXED: Always return data, even if subscription is canceled
        return response()->json([
            'success' => true,
            'household' => [
                'id' => $household->id,
                'name' => $household->name,
                'created_at' => $household->created_at,
                'subscription' => $household->currentSubscription ? [
                    'plan_name' => $household->currentSubscription->plan->name,
                    'status' => $household->currentSubscription->status,
                    'expires_at' => $household->currentSubscription->expires_at,
                    'canceled_at' => $household->currentSubscription->canceled_at, // ✅ Added
                ] : null,
                'users' => $household->users->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'is_billing_owner' => $user->is_billing_owner,
                        'active' => $user->active,
                    ];
                }),
            ],
        ]);
    }

    /**
     * Update household
     */
    public function update(Request $request)
    {
        $user = $request->user();
        $household = $user->household;

        if (!$user->isOwner()) {
            return response()->json([
                'success' => false,
                'message' => 'Only owner can update household'
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $household->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Household updated successfully',
            'household' => [
                'id' => $household->id,
                'name' => $household->name,
            ],
        ]);
    }

    /**
     * Generate invite code
     */
    public function generateInviteCode(Request $request)
    {
        $user = $request->user();
        $household = $user->household;

        if (!$user->isOwner()) {
            return response()->json([
                'success' => false,
                'message' => 'Only owner can generate invite codes'
            ], 403);
        }

        // ✅ Check if plan allows invite members
        $subscription = $household->currentSubscription;
        if (!$subscription || !$subscription->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription',
            ], 403);
        }

        $plan = $subscription->plan;
        if (!($plan->features['invite_members'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Your plan does not support inviting members. Please upgrade to Pertalite or higher.',
                'required_plan' => 'pertalite',
            ], 403);
        }

        $validated = $request->validate([
            'expires_in_days' => 'nullable|integer|min:1|max:30',
        ]);

        $inviteCode = InviteCode::createForHousehold(
            $household->id,
            $user->id,
            $validated['expires_in_days'] ?? 7
        );

        return response()->json([
            'success' => true,
            'message' => 'Invite code generated successfully',
            'invite_code' => [
                'code' => $inviteCode->code,
                'expires_at' => $inviteCode->expires_at,
                'invite_url' => config('app.frontend_url') . '/register?invite=' . $inviteCode->code,
            ],
        ], 201);
    }

    /**
     * Get active invite codes
     */
    public function inviteCodes(Request $request)
    {
        $household = $request->user()->household;

        $codes = InviteCode::where('household_id', $household->id)
                          ->with(['creator:id,name', 'usedBy:id,name'])
                          ->orderBy('created_at', 'desc')
                          ->get()
                          ->map(function ($code) {
                              return [
                                  'id' => $code->id,
                                  'code' => $code->code,
                                  'is_used' => $code->is_used,
                                  'is_valid' => $code->isValid(),
                                  'created_by' => [
                                      'id' => $code->creator->id,
                                      'name' => $code->creator->name,
                                  ],
                                  'used_by' => $code->usedBy ? [
                                      'id' => $code->usedBy->id,
                                      'name' => $code->usedBy->name,
                                  ] : null,
                                  'expires_at' => $code->expires_at,
                                  'used_at' => $code->used_at,
                                  'created_at' => $code->created_at,
                              ];
                          });

        return response()->json([
            'success' => true,
            'invite_codes' => $codes,
        ]);
    }

    /**
     * Revoke invite code
     */
    public function revokeInviteCode(Request $request, InviteCode $inviteCode)
    {
        $user = $request->user();

        if ($inviteCode->household_id !== $user->household_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        if (!$user->isOwner()) {
            return response()->json([
                'success' => false,
                'message' => 'Only owner can revoke invite codes'
            ], 403);
        }

        if ($inviteCode->is_used) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot revoke used invite code'
            ], 400);
        }

        $inviteCode->update(['expires_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Invite code revoked successfully',
        ]);
    }

    /**
     * Remove member from household
     */
    public function removeMember(Request $request, User $user)
    {
        $authUser = $request->user();

        if ($user->household_id !== $authUser->household_id) {
            return response()->json([
                'success' => false,
                'message' => 'User not in your household'
            ], 403);
        }

        if (!$authUser->isOwner()) {
            return response()->json([
                'success' => false,
                'message' => 'Only owner can remove members'
            ], 403);
        }

        if ($user->isOwner()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot remove owner'
            ], 400);
        }

        $user->update([
            'household_id' => null,
            'active' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Member removed successfully',
        ]);
    }

    /**
     * Update member role
     */
    public function updateMemberRole(Request $request, User $user)
    {
        $authUser = $request->user();

        if ($user->household_id !== $authUser->household_id) {
            return response()->json([
                'success' => false,
                'message' => 'User not in your household'
            ], 403);
        }

        if (!$authUser->isOwner()) {
            return response()->json([
                'success' => false,
                'message' => 'Only owner can update roles'
            ], 403);
        }

        $validated = $request->validate([
            'is_billing_owner' => 'required|boolean',
        ]);

        $user->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Member role updated successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'is_billing_owner' => $user->is_billing_owner,
            ],
        ]);
    }
}