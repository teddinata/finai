<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('merchant');
            $table->date('tanggal');
            $table->integer('subtotal')->default(0)->comment('in cents/smallest unit');
            $table->integer('diskon')->default(0)->comment('in cents/smallest unit');
            $table->integer('total')->comment('in cents/smallest unit');
            $table->enum('metode_pembayaran', [
                'cash', 
                'transfer', 
                'kartu_kredit', 
                'kartu_debit',
                'ewallet', 
                'other'
            ])->default('cash');
            $table->enum('source', ['scan', 'manual'])->default('manual');
            $table->text('notes')->nullable();
            $table->string('receipt_image')->nullable();
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
                  ->onDelete('set null');

            $table->index(['household_id', 'tanggal']);
            $table->index(['household_id', 'category_id']);
            $table->index(['household_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};