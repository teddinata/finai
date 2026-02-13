<?php
// database/migrations/2026_02_07_100000_create_accounts_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->string('name'); // "BCA", "Cash", "Stockbit"
            $table->enum('type', [
                'bank',           // Bank account
                'cash',           // Physical cash
                'ewallet',        // GoPay, OVO, Dana
                'credit_card',    // Credit card
                'investment',     // Stockbit, Bibit, etc
                'savings',        // Tabungan/deposito
                'other'
            ])->default('bank');
            $table->string('account_number')->nullable(); // No rekening
            $table->string('institution')->nullable(); // Nama bank/institusi
            $table->string('icon')->default('💳');
            $table->string('color', 7)->default('#3B82F6');
            $table->bigInteger('initial_balance')->default(0)->comment('in cents');
            $table->bigInteger('current_balance')->default(0)->comment('in cents');
            $table->boolean('include_in_total')->default(true); // Apakah dihitung di total net worth
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('household_id')
                  ->references('id')
                  ->on('households')
                  ->onDelete('cascade');

            $table->index(['household_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};