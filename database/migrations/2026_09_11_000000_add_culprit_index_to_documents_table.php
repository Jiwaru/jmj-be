<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Bukan FK ke culprit.id — id baris itu lahir di server dan berubah tiap
            // laporan disinkronkan ulang (IncidentReportSyncService::upsert() hapus lalu
            // buat ulang semua culprits). Ini cuma posisi pihak di array `culprits[]`
            // kiriman HP, sama pola dengan `vehicle_index` di culprit — dipakai lagi di
            // FE untuk mencocokkan KTP/SIM/foto kendaraan ke pihak yang benar waktu
            // ditampilkan, dan aman jadi yatim kalau pihaknya berubah di resubmit
            // berikutnya karena memang bukan FK.
            $table->unsignedTinyInteger('culprit_index')->nullable()->after('document_type');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('culprit_index');
        });
    }
};
