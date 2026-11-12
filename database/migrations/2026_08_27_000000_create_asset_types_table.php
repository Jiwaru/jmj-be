<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tingkat tengah hierarki katalog: category -> TYPE -> component.
     *
     * Sebelumnya `type` cuma kolom varchar bebas di asset_catalog, jadi "Guard Rail"
     * tidak punya baris dan tidak bisa dipasangi foto. Dijadikan tabel supaya:
     *   1. foto general per type punya tempat (standard_photo_id)
     *   2. `type` berhenti jadi ketikan bebas — "Guard Rail" vs "Guardrail" tidak
     *      lagi bisa hidup berdampingan dan memecah layar pemilihan 3-tap
     *
     * Nomor 000000 supaya jalan sebelum asset_catalog yang menunjuk ke sini.
     */
    public function up(): void
    {
        Schema::create('asset_types', function (Blueprint $table) {
            $table->id();
            $table->string('category', 30);
            $table->string('name', 150);
            // Menunjuk documents.id tanpa FK — documents dibuat belakangan (000003).
            // Sama polanya dengan asset_catalog.standard_photo_id.
            $table->unsignedBigInteger('standard_photo_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            // Satu nama type cuma boleh ada sekali dalam satu kategori.
            $table->unique(['category', 'name']);
        });

        DB::statement("ALTER TABLE asset_types ADD CONSTRAINT asset_types_category_check CHECK (category IN ('road_body','road_equipment','complementary_structure','support_facility','connecting_road'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_types');
    }
};
