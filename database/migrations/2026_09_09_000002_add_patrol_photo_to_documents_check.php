<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_document_type_check');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_document_type_check CHECK (document_type IN ('standard_photo','scene_photo','vehicle_photo','id_card_photo','driver_license_photo','vehicle_registration_photo','statement_photo','customer_signature','relief_letter','wo_photo','patrol_photo'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_document_type_check');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_document_type_check CHECK (document_type IN ('standard_photo','scene_photo','vehicle_photo','id_card_photo','driver_license_photo','vehicle_registration_photo','statement_photo','customer_signature','relief_letter','wo_photo'))");
    }
};
