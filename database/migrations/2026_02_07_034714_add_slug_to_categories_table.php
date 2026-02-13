<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Add parent_category_slug column if it doesn't exist
            if (!Schema::hasColumn('categories', 'parent_category_slug')) {
                $table->string('parent_category_slug')->nullable()->after('color');
                $table->index('parent_category_slug');
            }
        });

        // Populate parent_category_slug for existing categories
        DB::table('categories')->where('type', 'expense')->update([
            'parent_category_slug' => DB::raw("
                CASE 
                    WHEN name IN ('Makanan & Minuman', 'Restoran & Kafe', 'Groceries') THEN 'needs'
                    WHEN name IN ('Transportasi', 'Bensin', 'Parkir', 'Tol') THEN 'needs'
                    WHEN name IN ('Belanja Kebutuhan', 'Rumah Tangga', 'Kebersihan') THEN 'needs'
                    WHEN name IN ('Tagihan & Utilitas', 'Listrik', 'Air', 'Internet', 'Telepon') THEN 'needs'
                    WHEN name IN ('Kesehatan', 'Obat-obatan', 'Rumah Sakit', 'Asuransi Kesehatan') THEN 'needs'
                    
                    WHEN name IN ('Hiburan', 'Nonton Film', 'Gaming', 'Hobi') THEN 'wants'
                    WHEN name IN ('Fashion & Beauty', 'Baju', 'Sepatu', 'Kosmetik') THEN 'wants'
                    WHEN name IN ('Liburan', 'Travel', 'Hotel') THEN 'wants'
                    WHEN name IN ('Gadget & Elektronik') THEN 'wants'
                    WHEN name IN ('Olahraga & Fitness', 'Gym', 'Alat Olahraga') THEN 'wants'
                    
                    WHEN name IN ('Investasi', 'Saham', 'Reksa Dana', 'Crypto') THEN 'savings'
                    WHEN name IN ('Tabungan', 'Dana Darurat', 'Deposito') THEN 'savings'
                    WHEN name IN ('Cicilan', 'KPR', 'Pinjaman') THEN 'savings'
                    WHEN name IN ('Asuransi', 'Asuransi Jiwa', 'Asuransi Kendaraan') THEN 'savings'
                    
                    ELSE NULL
                END
            ")
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('parent_category_slug');
        });
    }
};