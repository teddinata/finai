<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add billing_cycle to subscriptions so we know if user chose monthly or yearly
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->enum('billing_cycle', ['monthly', 'yearly', 'lifetime', 'free'])
                  ->default('monthly')
                  ->after('plan_id');
        });

        // Add payment_token to payments (referenced in code but missing from migration)
        if (!Schema::hasColumn('payments', 'payment_token')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('payment_token')->nullable()->after('payment_gateway_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('billing_cycle');
        });

        if (Schema::hasColumn('payments', 'payment_token')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('payment_token');
            });
        }
    }
};