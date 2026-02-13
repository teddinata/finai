<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('feature')->comment('ai_scan, transaction, storage, etc');
            $table->integer('count')->default(0);
            $table->date('date');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('household_id')
                  ->references('id')
                  ->on('households')
                  ->onDelete('cascade');

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');

            $table->unique(['household_id', 'feature', 'date']);
            $table->index(['household_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_logs');
    }
};