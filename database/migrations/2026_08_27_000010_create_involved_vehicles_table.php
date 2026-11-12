<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('involved_vehicles', function (Blueprint $table) {
            $table->id();
            $table->uuid('incident_report_id');
            $table->foreign('incident_report_id', 'fk_iv_incident')->references('uuid')->on('incident_report')->cascadeOnDelete();
            // Jenis bodi kendaraan. Sebelumnya kolom ini keliru diisi golongan
            // ('class_1') — golongan tempatnya di vehicle_group, sebelah kanan.
            $table->string('vehicle_type', 20);
            // Golongan tarif tol. Sebelumnya ketikan bebas tanpa CHECK sama sekali,
            // diisi literal 'Golongan I' — jadi 'Gol I' dan 'golongan 1' sama-sama lolos.
            $table->string('vehicle_group', 20)->nullable();
            $table->string('plate_number', 15);
            $table->timestamps();

            $table->index('incident_report_id');
        });

        $in = fn (array $v) => collect($v)->map(fn ($s) => DB::getPdo()->quote($s))->implode(',');

        $types = $in(config('enums.vehicle_type'));
        $groups = $in(config('enums.vehicle_group'));

        DB::statement("ALTER TABLE involved_vehicles ADD CONSTRAINT involved_vehicles_type_check CHECK (vehicle_type IN ({$types}))");
        DB::statement("ALTER TABLE involved_vehicles ADD CONSTRAINT involved_vehicles_group_check CHECK (vehicle_group IS NULL OR vehicle_group IN ({$groups}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('involved_vehicles');
    }
};
