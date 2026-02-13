<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->nullable();
            $table->string('name');
            $table->string('icon')->default('📦');
            $table->string('color')->default('#6B7280');
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('household_id')
                  ->references('id')
                  ->on('households')
                  ->onDelete('cascade');

            $table->index(['household_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};