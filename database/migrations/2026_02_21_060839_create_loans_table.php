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
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->bigInteger('principal_amount');
            $table->bigInteger('interest_amount');
            $table->bigInteger('total_amount');
            $table->bigInteger('paid_amount')->default(0);
            $table->bigInteger('initial_paid_amount')->default(0);

            $table->integer('tenor_months');
            $table->integer('paid_periods')->default(0);
            $table->integer('initial_paid_periods')->default(0);
            $table->bigInteger('installment_amount');

            $table->date('start_date');
            $table->date('target_end_date');
            $table->date('next_payment_date');
            $table->date('last_payment_date')->nullable();

            $table->enum('status', ['active', 'paid_off', 'defaulted'])->default('active');

            $table->boolean('reminder_enabled')->default(true);
            $table->integer('reminder_days')->default(3);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};