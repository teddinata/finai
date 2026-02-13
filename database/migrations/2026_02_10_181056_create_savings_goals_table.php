<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('created_by');
            $table->string('name'); // "Dana Darurat", "DP Rumah"
            $table->text('description')->nullable();
            $table->bigInteger('target_amount')->comment('in cents');
            $table->bigInteger('current_amount')->default(0)->comment('in cents');
            $table->date('deadline')->nullable();
            $table->string('icon')->default('🎯');
            $table->string('color', 7)->default('#10B981');
            $table->enum('status', ['active', 'completed', 'archived'])->default('active');
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->timestamps();

            $table->foreign('household_id')
                  ->references('id')
                  ->on('households')
                  ->onDelete('cascade');

            $table->foreign('created_by')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->index(['household_id', 'status']);
        });

        // Pivot table untuk link savings goal dengan transactions
        Schema::create('savings_goal_contributions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('savings_goal_id');
            $table->unsignedBigInteger('transaction_id');
            $table->bigInteger('amount')->comment('in cents');
            $table->timestamps();

            $table->foreign('savings_goal_id')
                  ->references('id')
                  ->on('savings_goals')
                  ->onDelete('cascade');

            $table->foreign('transaction_id')
                  ->references('id')
                  ->on('transactions')
                  ->onDelete('cascade');

            $table->unique(['savings_goal_id', 'transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_goal_contributions');
        Schema::dropIfExists('savings_goals');
    }
};