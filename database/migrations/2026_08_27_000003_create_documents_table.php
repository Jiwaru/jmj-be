<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('documentable_type', 60);
            $table->string('documentable_id', 36);
            $table->string('document_type', 30);
            $table->string('path', 255);
            $table->string('mime_type', 60);
            $table->integer('size_bytes');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamps();

            $table->index(['documentable_type', 'documentable_id']);
            $table->index('document_type');
            // Penjaga idempotensi unggah: server menyusun path deterministik dari
            // uuid laporan + file_uuid, lalu upsert on path. Tanpa unique ini,
            // kiriman ulang karena sinyal putus melahirkan baris kedua.
            $table->unique('path');
        });

        // vehicle_photo         = foto kendaraannya
        // vehicle_registration_photo = foto STNK-nya. Beda benda, sengaja dipisah.
        // id_card_photo = KTP; SIM punya nilai sendiri karena di UI kotaknya terpisah.
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_document_type_check CHECK (document_type IN ('standard_photo','scene_photo','vehicle_photo','id_card_photo','driver_license_photo','vehicle_registration_photo','statement_photo','customer_signature','relief_letter','wo_photo'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
