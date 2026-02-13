<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('account_id')->nullable();
            
            $table->string('name'); // "Saham BBCA", "Reksa Dana XYZ"
            $table->string('symbol')->nullable(); // "BBCA", "GOTO"
            $table->enum('type', [
                'stocks',          // Saham
                'mutual_funds',    // Reksa Dana
                'bonds',           // Obligasi
                'crypto',          // Cryptocurrency
                'gold',            // Emas
                'property',        // Properti
                'deposit',         // Deposito
                'other'
            ])->default('stocks');
            
            // Investment details
            $table->decimal('quantity', 20, 8)->default(0); // Jumlah unit/lot
            $table->bigInteger('avg_buy_price')->default(0)->comment('Average buy price in cents');
            $table->bigInteger('initial_amount')->default(0)->comment('Total invested in cents');
            $table->bigInteger('current_value')->default(0)->comment('Current value in cents');
            $table->decimal('current_price', 20, 8)->nullable()->comment('Current price per unit');
            
            // Metadata
            $table->string('platform')->nullable(); // "Stockbit", "Bibit", "Ajaib"
            $table->string('currency', 3)->default('IDR');
            $table->date('purchase_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('icon')->default('📈');
            $table->string('color', 7)->default('#3B82F6');
            
            $table->enum('status', ['active', 'sold', 'archived'])->default('active');
            $table->timestamp('last_updated_at')->nullable();
            
            $table->timestamps();

            $table->foreign('household_id')
                  ->references('id')
                  ->on('households')
                  ->onDelete('cascade');

            $table->foreign('created_by')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->foreign('account_id')
                  ->references('id')
                  ->on('accounts')
                  ->onDelete('set null');

            $table->index(['household_id', 'status']);
            $table->index(['household_id', 'type']);
        });

        // Investment transactions (buy/sell history)
        Schema::create('investment_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('investment_id');
            $table->enum('type', ['buy', 'sell', 'dividend', 'fee'])->default('buy');
            $table->decimal('quantity', 20, 8)->default(0);
            $table->bigInteger('price_per_unit')->comment('in cents');
            $table->bigInteger('total_amount')->comment('in cents');
            $table->bigInteger('fee')->default(0)->comment('in cents');
            $table->date('transaction_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('investment_id')
                  ->references('id')
                  ->on('investments')
                  ->onDelete('cascade');

            $table->index(['investment_id', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_transactions');
        Schema::dropIfExists('investments');
    }
};