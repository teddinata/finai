<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('user_id')->comment('who initiated the payment');
            $table->integer('amount')->comment('in cents/smallest currency unit');
            $table->integer('tax')->default(0);
            $table->integer('total')->comment('amount + tax');
            $table->string('currency', 3)->default('IDR');
            $table->string('payment_method')->default('midtrans');
            $table->string('payment_gateway_id')->nullable()->comment('external payment ID');
            $table->string('snap_token')->nullable()->comment('Midtrans snap token');
            $table->enum('status', ['pending', 'paid', 'failed', 'refunded', 'expired'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->json('metadata')->nullable()->comment('gateway response, etc');
            $table->timestamps();

            $table->foreign('subscription_id')
                  ->references('id')
                  ->on('subscriptions')
                  ->onDelete('set null');

            $table->foreign('household_id')
                  ->references('id')
                  ->on('households')
                  ->onDelete('cascade');

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->index(['household_id', 'status']);
            $table->index(['payment_gateway_id']);
            $table->index(['created_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};