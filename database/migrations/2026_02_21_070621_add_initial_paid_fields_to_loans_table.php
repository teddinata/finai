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
            if (!Schema::hasColumn('loans', 'initial_paid_amount')) {
                $table->unsignedBigInteger('initial_paid_amount')->default(0)->after('paid_amount')->comment('Amount already paid at time of import');
            }
            if (!Schema::hasColumn('loans', 'initial_paid_periods')) {
                $table->unsignedInteger('initial_paid_periods')->default(0)->after('paid_periods')->comment('Number of months already paid at time of import');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            if (Schema::hasColumn('loans', 'initial_paid_amount')) {
                $table->dropColumn('initial_paid_amount');
            }
            if (Schema::hasColumn('loans', 'initial_paid_periods')) {
                $table->dropColumn('initial_paid_periods');
            }
        });
    }
};