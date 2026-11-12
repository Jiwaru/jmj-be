<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kebutuhan desain Figma aplikasi Petugas Maintenance (MO-01 s.d. MO-10), 1 Okt.
 *
 * SEMUANYA MENAMBAH, tidak ada yang membuang — kecuali `target_date` yang naik tipe
 * dari date ke timestamp. Kolom baru nullable, supaya aplikasi luring versi lama yang
 * antreannya belum terkirim tetap diterima, bukan 422 yang tidak bisa dibetulkan di
 * bahu jalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Target berjam: "04 Sep 2026, 18:00 WIB". Konsep §7 menyebut jalur perbaikan
        // diukur JAM sampai hari, jadi tanggal saja memang kurang. Nama kolom tetap
        // supaya FE tidak patah; isi lama dibaca "paling lambat hari itu" → 23:59:59.
        //
        // BUKAN sla_deadline. Kolom itu milik SLA resmi kantor (pertanyaan #12 konsep,
        // belum dijawab) dan tetap NULL. Target di sini diketik Unit Maintenance.
        DB::statement("ALTER TABLE work_orders ALTER COLUMN target_date TYPE timestamp(0) USING target_date + time '23:59:59'");

        // Titik peta "Lokasi Presisi". Sumbernya GPS HP petugas patroli saat melapor,
        // disalin ke WO waktu terbit — pola yang sama dengan km/m/route/lane.
        foreach (['incident_report', 'patrol_report', 'work_orders'] as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
            });
            DB::statement("ALTER TABLE {$tabel} ADD CONSTRAINT {$tabel}_coordinate_pair_check CHECK ((latitude IS NULL) = (longitude IS NULL))");
        }

        // Koordinator regu. Satu per WO, dijaga index unik parsial — dua koordinator
        // berarti tidak ada koordinator.
        Schema::table('operational_team', function (Blueprint $table) {
            $table->boolean('is_coordinator')->default(false);
        });
        DB::statement('CREATE UNIQUE INDEX operational_team_one_coordinator ON operational_team (work_order_id) WHERE is_coordinator');

        Schema::table('work_order_logs', function (Blueprint $table) {
            // "Judul" di Catat Progress dan di kartu Activity Log.
            $table->string('title', 120)->nullable();
            // Penerima oper shift. Cuma terisi di baris shift_handover.
            $table->foreignId('handover_to_id')->nullable()->constrained('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE work_order_logs ADD CONSTRAINT work_order_logs_handover_to_check CHECK (handover_to_id IS NULL OR entry_type = 'shift_handover')");

        // "Target Revisi 06 Sep 2026, 21:00 WIB". Cuma bermakna pada review yang
        // mengembalikan pekerjaan.
        Schema::table('work_order_reviews', function (Blueprint $table) {
            $table->timestamp('revision_deadline')->nullable();
        });
        DB::statement("ALTER TABLE work_order_reviews ADD CONSTRAINT work_order_reviews_revision_deadline_check CHECK (revision_deadline IS NULL OR outcome <> 'Diterima')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE work_order_reviews DROP CONSTRAINT work_order_reviews_revision_deadline_check');
        Schema::table('work_order_reviews', fn (Blueprint $t) => $t->dropColumn('revision_deadline'));

        DB::statement('ALTER TABLE work_order_logs DROP CONSTRAINT work_order_logs_handover_to_check');
        Schema::table('work_order_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handover_to_id');
            $table->dropColumn('title');
        });

        DB::statement('DROP INDEX operational_team_one_coordinator');
        Schema::table('operational_team', fn (Blueprint $t) => $t->dropColumn('is_coordinator'));

        foreach (['incident_report', 'patrol_report', 'work_orders'] as $tabel) {
            DB::statement("ALTER TABLE {$tabel} DROP CONSTRAINT {$tabel}_coordinate_pair_check");
            Schema::table($tabel, fn (Blueprint $t) => $t->dropColumn(['latitude', 'longitude']));
        }

        DB::statement('ALTER TABLE work_orders ALTER COLUMN target_date TYPE date USING target_date::date');
    }
};
