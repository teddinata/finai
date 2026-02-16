<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('type', ['percentage', 'fixed']);
            $table->integer('value')->comment('Amount in IDR if fixed, or percentage (0-100) if percentage');
            $table->integer('max_discount_amount')->nullable()->comment('Max discount amount for percentage type');
            $table->integer('min_purchase_amount')->default(0);
            $table->integer('max_uses')->nullable()->comment('Total usage limit, null for unlimited');
            $table->integer('max_uses_per_household')->default(1);
            $table->integer('used_count')->default(0);
            $table->json('applicable_plans')->nullable()->comment('Array of plan IDs, null for all plans');
            $table->timestamp('valid_from');
            $table->timestamp('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['code', 'is_active']);
            $table->index(['valid_from', 'valid_until']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};