<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('account_id')->nullable();
            
            $table->string('name'); // "Cicilan Motor", "Netflix Subscription"
            $table->text('description')->nullable();
            $table->enum('type', ['income', 'expense'])->default('expense');
            $table->string('merchant')->nullable();
            $table->bigInteger('amount')->comment('in cents');
            
            // Recurrence settings
            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'yearly'])->default('monthly');
            $table->integer('interval')->default(1)->comment('every X days/weeks/months/years');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_occurrence');
            $table->integer('occurrences_count')->default(0)->comment('how many times generated');
            $table->integer('max_occurrences')->nullable()->comment('stop after X times');
            
            $table->enum('status', ['active', 'paused', 'completed', 'cancelled'])->default('active');
            $table->boolean('auto_create')->default(true)->comment('auto-create transaction');
            $table->boolean('send_notification')->default(true);
            $table->text('notes')->nullable();
            
            $table->timestamps();

            $table->foreign('household_id')
                  ->references('id')
                  ->on('households')
                  ->onDelete('cascade');

            $table->foreign('created_by')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->foreign('category_id')
                  ->references('id')
                  ->on('categories')
                  ->onDelete('restrict');

            $table->foreign('account_id')
                  ->references('id')
                  ->on('accounts')
                  ->onDelete('set null');

            $table->index(['household_id', 'status']);
            $table->index('next_occurrence');
        });

        // Track which transactions were generated from recurring
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('recurring_transaction_id')->nullable()->after('account_id');
            
            $table->foreign('recurring_transaction_id')
                  ->references('id')
                  ->on('recurring_transactions')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['recurring_transaction_id']);
            $table->dropColumn('recurring_transaction_id');
        });
        
        Schema::dropIfExists('recurring_transactions');
    }
};