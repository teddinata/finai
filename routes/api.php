<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\SavingsGoalController;
use App\Http\Controllers\Api\RecurringTransactionController;
use App\Http\Controllers\Api\InvestmentController;


/*
 |--------------------------------------------------------------------------
 | API Routes
 |--------------------------------------------------------------------------
 */

// Webhooks (dari payment gateway)
Route::post('/webhooks/xendit', [WebhookController::class , 'xendit']);

// Public routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class , 'register']);
    Route::post('/register/invite', [AuthController::class , 'registerWithInvite']);
    Route::post('/login', [AuthController::class , 'login']);
});

// Plans (public - untuk pricing page)
Route::get('/plans', [PlanController::class , 'index']);
Route::get('/plans/{plan}', [PlanController::class , 'show']);


Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class , 'verify'])
    ->middleware(['signed'])
    ->name('verification.verify');

// Alternative: Verify via API with token
Route::post('/email/verify', [EmailVerificationController::class , 'verifyWithToken'])
    ->middleware('auth:sanctum');

// Protected routes
Route::middleware('auth:sanctum')->group(function () {

    Route::prefix('email')->group(function () {
            Route::get('/status', [EmailVerificationController::class , 'status']);
            Route::post('/send', [EmailVerificationController::class , 'send'])
                ->middleware('throttle:6,1');
            Route::post('/resend', [EmailVerificationController::class , 'resend'])
                ->middleware('throttle:3,1');
        }
        );

        // Auth
        Route::prefix('auth')->group(function () {
            Route::post('/logout', [AuthController::class , 'logout']);
            Route::get('/me', [AuthController::class , 'me']);
            Route::put('/profile', [AuthController::class , 'updateProfile']);
            Route::post('/change-password', [AuthController::class , 'changePassword']);
        }
        );

        // Subscription Management
        Route::prefix('subscription')->group(function () {
            Route::get('/', [SubscriptionController::class , 'current']);
            Route::post('/subscribe/{plan}', [SubscriptionController::class , 'subscribe']);
            Route::post('/cancel', [SubscriptionController::class , 'cancel']);
            Route::post('/auto-renew/enable', [SubscriptionController::class , 'enableAutoRenew']);
            Route::post('/auto-renew/disable', [SubscriptionController::class , 'disableAutoRenew']);
        }
        );

        // Payment Management
        Route::prefix('payments')->group(function () {
            Route::post('/', [PaymentController::class , 'create']);
            Route::get('/', [PaymentController::class , 'history']);
            Route::get('/{payment}', [PaymentController::class , 'status']);
            Route::post('/{payment}/cancel', [PaymentController::class , 'cancel']);
        }
        );

        // Invoice Management
        Route::prefix('invoices')->group(function () {
            Route::get('/', [InvoiceController::class , 'index']);
            Route::get('/{invoice}', [InvoiceController::class , 'show']);
            Route::get('/{invoice}/download', [InvoiceController::class , 'download']);
        }
        );

        // Household Management
        Route::prefix('household')->middleware('check.subscription')->group(function () {
            Route::get('/', [HouseholdController::class , 'show']);
            Route::put('/', [HouseholdController::class , 'update']);

            // Invite codes - check if plan allows invites
            Route::post('/invite-codes', [HouseholdController::class , 'generateInviteCode']);
            Route::get('/invite-codes', [HouseholdController::class , 'inviteCodes']);
            Route::post('/invite-codes/{inviteCode}/revoke', [HouseholdController::class , 'revokeInviteCode']);

            // Member management
            Route::delete('/members/{user}', [HouseholdController::class , 'removeMember']);
            Route::put('/members/{user}/role', [HouseholdController::class , 'updateMemberRole']);
        }
        );

        // Accounts - check subscription & account limit
        Route::prefix('accounts')->middleware('check.subscription')->group(function () {
            Route::get('/', [AccountController::class , 'index']);
            Route::get('/{account}', [AccountController::class , 'show']);
            Route::post('/', [AccountController::class , 'store']); // Will check limit in controller
            Route::put('/{account}', [AccountController::class , 'update']);
            Route::delete('/{account}', [AccountController::class , 'destroy']);
        }
        );

        // Transfers - require subscription
        Route::prefix('transfers')->middleware('check.subscription')->group(function () {
            Route::get('/', [TransferController::class , 'index']);
            Route::post('/', [TransferController::class , 'store']);
            Route::get('/{transfer}', [TransferController::class , 'show']);
            Route::put('/{transfer}', [TransferController::class , 'update']);
            Route::delete('/{transfer}', [TransferController::class , 'destroy']);
        }
        );

        // Categories
        Route::prefix('categories')->middleware('check.subscription')->group(function () {
            Route::get('/', [CategoryController::class , 'index']);
            Route::get('/{category}', [CategoryController::class , 'show']);

            // Custom categories require feature
            Route::post('/', [CategoryController::class , 'store']);
            Route::put('/{category}', [CategoryController::class , 'update']);
            Route::delete('/{category}', [CategoryController::class , 'destroy']);
            Route::post('/reorder', [CategoryController::class , 'reorder']);
        }
        );

        // Budget - requires module access
        Route::prefix('budget')->middleware(['check.subscription', 'check.module:budget'])->group(function () {
            Route::get('/overview', [BudgetController::class , 'overview']);
            Route::get('/templates', [BudgetController::class , 'templates']);
            Route::post('/rule', [BudgetController::class , 'createRule']);
            Route::get('/limits', [BudgetController::class , 'categoryLimits']);
            Route::post('/limits', [BudgetController::class , 'createLimit']);
        }
        );

        // Transactions
        Route::prefix('transactions')->middleware('check.subscription')->group(function () {
            Route::get('/', [TransactionController::class , 'index']);
            Route::get('/{transaction}', [TransactionController::class , 'show']);

            // Manual transaction (with limit check)
            Route::post('/', [TransactionController::class , 'store'])
                ->middleware('check.feature:transaction');

            // AI Scan (with both limits check)
            Route::post('/scan', [TransactionController::class , 'scan'])
                ->middleware(['check.feature:ai_scan', 'check.feature:transaction']);

            Route::put('/{transaction}', [TransactionController::class , 'update']);
            Route::delete('/{transaction}', [TransactionController::class , 'destroy']);
        }
        );

        // Analytics - requires module access
        Route::prefix('analytics')->middleware(['check.subscription', 'check.module:analytics'])->group(function () {
            Route::get('/summary', [AnalyticsController::class , 'summary']);
            Route::get('/by-category', [AnalyticsController::class , 'byCategory']);
            Route::get('/by-merchant', [AnalyticsController::class , 'byMerchant']);
            Route::get('/timeline', [AnalyticsController::class , 'timeline']);
            Route::get('/comparison', [AnalyticsController::class , 'comparison']);
            Route::get('/trends', [AnalyticsController::class , 'trends']);
        }
        );

        // Usage Statistics
        Route::prefix('usage')->middleware('check.subscription')->group(function () {
            Route::get('/', [UsageController::class , 'index']);
            Route::get('/history', [UsageController::class , 'history']);
            Route::get('/daily', [UsageController::class , 'daily']);
            Route::post('/can-use', [UsageController::class , 'canUse']);
        }
        );
    });

Route::middleware(['auth:sanctum', 'check.subscription'])->group(function () {

    // ===== SAVINGS GOALS =====
    Route::prefix('savings-goals')->group(function () {
            Route::get('/', [SavingsGoalController::class , 'index']);
            Route::get('/{savingsGoal}', [SavingsGoalController::class , 'show']);
            Route::post('/', [SavingsGoalController::class , 'store']);
            Route::put('/{savingsGoal}', [SavingsGoalController::class , 'update']);
            Route::delete('/{savingsGoal}', [SavingsGoalController::class , 'destroy']);

            // Contributions
            Route::post('/{savingsGoal}/contribute', [SavingsGoalController::class , 'addContribution']);
            Route::delete('/{savingsGoal}/contributions/{transaction}', [SavingsGoalController::class , 'removeContribution']);
            Route::post('/{savingsGoal}/recalculate', [SavingsGoalController::class , 'recalculate']);
        }
        );

        // ===== RECURRING TRANSACTIONS =====
        Route::prefix('recurring-transactions')->group(function () {
            Route::get('/', [RecurringTransactionController::class , 'index']);
            Route::get('/{recurringTransaction}', [RecurringTransactionController::class , 'show']);
            Route::post('/', [RecurringTransactionController::class , 'store']);
            Route::put('/{recurringTransaction}', [RecurringTransactionController::class , 'update']);
            Route::delete('/{recurringTransaction}', [RecurringTransactionController::class , 'destroy']);

            // Actions
            Route::post('/{recurringTransaction}/pause', [RecurringTransactionController::class , 'pause']);
            Route::post('/{recurringTransaction}/resume', [RecurringTransactionController::class , 'resume']);
            Route::post('/{recurringTransaction}/cancel', [RecurringTransactionController::class , 'cancel']);
            Route::post('/{recurringTransaction}/generate', [RecurringTransactionController::class , 'generateNow']);
        }
        );

        // ===== INVESTMENTS =====
        Route::prefix('investments')->group(function () {
            Route::get('/', [InvestmentController::class , 'index']);
            Route::get('/{investment}', [InvestmentController::class , 'show']);
            Route::post('/', [InvestmentController::class , 'store']);
            Route::put('/{investment}', [InvestmentController::class , 'update']);
            Route::delete('/{investment}', [InvestmentController::class , 'destroy']);

            // Transactions
            Route::post('/{investment}/buy', [InvestmentController::class , 'buy']);
            Route::post('/{investment}/sell', [InvestmentController::class , 'sell']);
            Route::post('/{investment}/update-price', [InvestmentController::class , 'updatePrice']);
        }
        );
    });

// Admin Routes (Protected by admin middleware)
Route::prefix('admin')->middleware(['auth:sanctum', 'check.admin'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [AdminController::class , 'dashboard']);

    // Users Management
    Route::get('/users', [AdminController::class , 'users']);
    Route::get('/users/{userId}', [AdminController::class , 'userDetail']);
    Route::put('/users/{userId}', [AdminController::class , 'updateUser']);

    // Households Management
    Route::get('/households', [AdminController::class , 'households']);
    Route::get('/households/{householdId}', [AdminController::class , 'householdDetail']);

    // Subscriptions Management
    Route::get('/subscriptions', [AdminController::class , 'subscriptions']);
    Route::post('/subscriptions/{subscriptionId}/cancel', [AdminController::class , 'cancelSubscription']);

    // Payments Management
    Route::get('/payments', [AdminController::class , 'payments']);
    Route::get('/revenue', [AdminController::class , 'revenue']);

    // Plans Management
    Route::get('/plans', [AdminController::class , 'plans']);
    Route::put('/plans/{planId}', [AdminController::class , 'updatePlan']);

    // System Statistics
    Route::get('/stats', [AdminController::class , 'systemStats']);
});