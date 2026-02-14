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
        // Modify source enum to include 'chat'
        // Using raw SQL as Doctrine DBAL has issues with ENUM modification
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN source ENUM('scan', 'manual', 'chat') DEFAULT 'manual'");
        }
    }

    public function down(): void
    {
        // Revert back
        if (DB::getDriverName() !== 'sqlite') {
            // Note: This might fail if there are 'chat' records, but acceptable for rollback
            DB::statement("ALTER TABLE transactions MODIFY COLUMN source ENUM('scan', 'manual') DEFAULT 'manual'");
        }
    }
};