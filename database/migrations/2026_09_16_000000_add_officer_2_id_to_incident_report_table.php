<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redesain 16 Sep: penanda tangan BA Kejadian dipilih petugas lapangan saat submit,
 * bukan MCS saat mengesahkan. Penanda tangan 1 = pelapor (`user_id`, sudah ada).
 * Ini cuma menambah slot untuk penanda tangan 2.
 *
 * NOT NULL: SK mewajibkan BA Kejadian untuk SEMUA insiden, jadi tidak ada baris
 * yang sah tanpa penanda tangan keduanya sejak awal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_report', function (Blueprint $table) {
            $table->foreignId('officer_2_id')->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incident_report', function (Blueprint $table) {
            $table->dropConstrainedForeignId('officer_2_id');
        });
    }
};
