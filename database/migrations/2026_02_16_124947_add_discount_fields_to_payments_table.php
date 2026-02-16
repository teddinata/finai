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
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('voucher_id')->nullable()->after('payment_method')->constrained('vouchers')->nullOnDelete();
            $table->integer('discount_amount')->default(0)->after('voucher_id');
            $table->integer('original_amount')->nullable()->after('amount')->comment('Base amount before any discount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
        //
        });
    }
};