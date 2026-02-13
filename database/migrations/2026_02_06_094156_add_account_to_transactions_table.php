<?php
// database/migrations/2026_02_07_100002_add_account_to_transactions.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Tambah account_id, hapus metode_pembayaran nantinya
            $table->unsignedBigInteger('account_id')->nullable()->after('category_id');
            
            $table->foreign('account_id')
                  ->references('id')
                  ->on('accounts')
                  ->onDelete('set null');
            
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropIndex(['account_id']);
            $table->dropColumn('account_id');
        });
    }
};