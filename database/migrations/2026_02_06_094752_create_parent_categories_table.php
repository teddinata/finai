<?php
// database/migrations/2026_02_08_100000_create_parent_categories_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // "Kebutuhan", "Keinginan", "Tabungan"
            $table->string('slug')->unique(); // "needs", "wants", "savings"
            $table->string('icon')->default('📊');
            $table->string('color', 7)->default('#6B7280');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Add parent_category_id to categories
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_category_id')->nullable()->after('household_id');
            
            $table->foreign('parent_category_id')
                  ->references('id')
                  ->on('parent_categories')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['parent_category_id']);
            $table->dropColumn('parent_category_id');
        });
        
        Schema::dropIfExists('parent_categories');
    }
};