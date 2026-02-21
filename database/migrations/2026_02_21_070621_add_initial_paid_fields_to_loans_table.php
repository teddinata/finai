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
        Schema::table('loans', function (Blueprint $table) {
            $table->unsignedBigInteger('initial_paid_amount')->default(0)->after('paid_amount')->comment('Amount already paid at time of import');
            $table->unsignedInteger('initial_paid_periods')->default(0)->after('paid_periods')->comment('Number of months already paid at time of import');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
        //
        });
    }
};