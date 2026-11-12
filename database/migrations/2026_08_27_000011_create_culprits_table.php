<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('culprit', function (Blueprint $table) {
            $table->id();
            $table->uuid('incident_report_id');
            $table->foreign('incident_report_id', 'fk_culprit_incident')->references('uuid')->on('incident_report')->cascadeOnDelete();
            // Kendaraan yang dikemudikan pelaku ini. Di layar, satu kartu "Pihak N"
            // memuat orangnya DAN kendaraannya, jadi pasangannya lahir dari satu form
            // — tapi tetap kolom sendiri karena dua-duanya tidak selalu ada: tabrak
            // lari punya kendaraan tanpa pelaku, dan pemilik yang tidak ikut mengemudi
            // punya pelaku tanpa kendaraan.
            //
            // HP tidak mengirim id ini (bigint-nya lahir di server saat sync). Yang
            // dikirim `vehicle_index`, posisi kendaraan di array kiriman yang sama;
            // service yang menerjemahkannya. Lihat IncidentReportSyncService.
            //
            // nullOnDelete, bukan cascade: menghapus kendaraan dari daftar tidak boleh
            // ikut menghapus orangnya — datanya masih dipakai menagih.
            $table->foreignId('involved_vehicle_id')->nullable()
                ->constrained('involved_vehicles')->nullOnDelete();
            $table->string('full_name', 120);
            // Status Pihak Kesatu di formulir BA Kerusakan: "Dalam hal ini disebut
            // sebagai pemilik / pengemudi / pengurus kendaraan jenis ...". Tiga
            // pilihan yang dilingkari di kertas.
            //
            // Nullable, dan itu bukan kelonggaran: tabrak lari nol baris culprit,
            // dan laporan yang terlanjur diketik luring dengan aplikasi versi lama
            // tidak boleh kena 422 waktu sync. Yang kosong tercetak titik-titik.
            $table->string('party_role', 20)->nullable();
            $table->char('national_id', 16)->nullable();
            $table->string('phone_number', 20)->nullable();
            $table->text('address')->nullable();
            $table->smallInteger('age')->nullable();
            $table->string('occupation', 80)->nullable();
            $table->string('driver_license_number', 20)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('incident_report_id');
            // WAJIB, bukan optimasi belakangan: PostgreSQL tidak meng-index kolom FK
            // otomatis (MySQL iya), dan FK di atas ON DELETE SET NULL. Tanpa index
            // ini setiap DELETE FROM involved_vehicles memaksa Postgres memindai
            // SELURUH tabel culprit untuk mencari baris yang menunjuknya — dan
            // penghapusan itu terjadi di setiap PUT /incident-reports/{uuid}, karena
            // baris anak diganti seisi-isinya. Ongkosnya tumbuh mengikuti jumlah
            // pelaku seluruh sistem, bukan jumlah pelaku di laporan yang disimpan.
            $table->index('involved_vehicle_id');
        });

        // Daftarnya di config/enums.php — satu sumber untuk CHECK, Rule::in(), dan
        // bundel luring. Diketik ulang di sini berarti CHECK dan validasi bisa beda
        // satu huruf, dan itu ketahuannya di produksi.
        $partyRole = collect(config('enums.party_role'))
            ->map(fn ($s) => DB::getPdo()->quote($s))->implode(',');

        DB::statement("ALTER TABLE culprit ADD CONSTRAINT culprit_party_role_check CHECK (party_role IS NULL OR party_role IN ({$partyRole}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('culprit');
    }
};
