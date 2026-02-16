<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedBigInteger('price_yearly')->nullable()->after('discount_price');
            $table->unsignedBigInteger('discount_price_yearly')->nullable()->after('price_yearly');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['price_yearly', 'discount_price_yearly']);
        });
    }
};