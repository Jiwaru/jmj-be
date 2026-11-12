<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            // category dan type pindah ke asset_types. Kalau category ditinggal
            // di sini juga, nilainya bisa berbeda dari induknya dan tidak ada
            // yang menjaga — satu fakta, satu tempat.
            $table->foreignId('asset_type_id')->constrained('asset_types')->restrictOnDelete();
            $table->string('component', 150)->nullable();
            $table->text('description')->nullable();
            $table->string('unit_of_measurement', 15);
            $table->unsignedBigInteger('standard_photo_id')->nullable();
            $table->jsonb('specification')->nullable();
            $table->smallInteger('designlife_years')->nullable();
            $table->string('primary_vendor', 150)->nullable();
            $table->timestamps();

            $table->index(['asset_type_id', 'component']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_catalog');
    }
};
