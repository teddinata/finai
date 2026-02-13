<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('plan_id');
            $table->enum('status', ['trial', 'active', 'pending', 'canceled', 'expired', 'past_due'])->default('active');
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->foreign('household_id')
                  ->references('id')
                  ->on('households')
                  ->onDelete('cascade');

            $table->foreign('plan_id')
                  ->references('id')
                  ->on('plans')
                  ->onDelete('restrict');

            $table->index(['household_id', 'status']);
            $table->index(['expires_at', 'status']);
        });

        // Add foreign key to households table
        Schema::table('households', function (Blueprint $table) {
            $table->foreign('current_subscription_id')
                  ->references('id')
                  ->on('subscriptions')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropForeign(['current_subscription_id']);
        });
        
        Schema::dropIfExists('subscriptions');
    }
};