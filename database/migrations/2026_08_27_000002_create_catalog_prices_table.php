<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_catalog_id')->constrained('asset_catalog')->restrictOnDelete();
            // Sengaja nullable, walau seeder sekarang selalu mengisinya (= max_price,
            // keputusan 3 Sep). Perhitungan tetap membaca max_price (keputusan 31 Ags),
            // jadi price yang kosong harus tetap sah — harga yang diimpor dari sumber
            // lain belum tentu membawanya. NOT NULL memaksa pengisian 0, dan 0 gagalnya
            // lebih senyap daripada NULL.
            $table->decimal('price', 15, 2)->nullable();
            $table->decimal('min_price', 15, 2)->nullable();
            $table->decimal('max_price', 15, 2)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('change_reason', 30);
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['asset_catalog_id', 'effective_from']);
        });

        DB::statement("ALTER TABLE catalog_prices ADD CONSTRAINT catalog_prices_change_reason_check CHECK (change_reason IN ('inflation','vendor_adjustment','admin_revision'))");
        DB::statement("ALTER TABLE catalog_prices ADD CONSTRAINT catalog_prices_effective_range_check CHECK (effective_to IS NULL OR effective_to >= effective_from)");
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_prices');
    }
};
