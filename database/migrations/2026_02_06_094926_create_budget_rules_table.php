<?php
// database/migrations/2026_02_08_100001_create_budget_rules_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->string('name'); // "50/30/20 Rule", "Custom Budget"
            $table->boolean('is_active')->default(true);
            $table->json('allocations'); // {"needs": 50, "wants": 30, "savings": 20}
            $table->integer('monthly_income_target')->default(0)->comment('in cents');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('household_id')
                  ->references('id')
                  ->on('households')
                  ->onDelete('cascade');

            $table->index(['household_id', 'is_active']);
        });

        Schema::create('budget_limits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('parent_category_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->integer('limit_amount')->comment('in cents');
            $table->enum('period_type', ['monthly', 'yearly'])->default('monthly');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('household_id')
                  ->references('id')
                  ->on('households')
                  ->onDelete('cascade');

            $table->foreign('parent_category_id')
                  ->references('id')
                  ->on('parent_categories')
                  ->onDelete('cascade');

            $table->foreign('category_id')
                  ->references('id')
                  ->on('categories')
                  ->onDelete('cascade');

            $table->index(['household_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_limits');
        Schema::dropIfExists('budget_rules');
    }
};