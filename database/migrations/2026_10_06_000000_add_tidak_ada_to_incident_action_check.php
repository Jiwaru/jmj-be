<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tindakan yang diambil bertambah 'Tidak Ada' (6 Okt) — BAK yang cuma melaporkan
 * kejadian tanpa tindakan apa pun.
 *
 * CHECK di bawah dibangun dari config SAAT MIGRATE, jadi mengubah config saja tidak
 * cukup: validasi menerima 'Tidak Ada' lalu database menolaknya (500). Ekspresinya
 * sama persis dengan migration pembuatnya (2026_08_27_000004), cuma daftarnya berganti.
 *
 * up() cuma menambah nilai, jadi semua baris lama tetap lolos — tidak perlu NOT VALID.
 * down() sebaliknya: baris yang sudah memakai 'Tidak Ada' akan melanggar daftar lama,
 * jadi dipasang NOT VALID supaya rollback tidak gagal gara-gara data.
 */
return new class extends Migration
{
    private const TINDAKAN_LAMA = [
        'Pemasangan Rambu Darurat (Cone/Barrier)',
        'Pengaturan Arus Lalu Lintas (Trafik)',
        'Evakuasi Pengemudi / Korban',
        'Koordinasi Unit Derek',
        'Koordinasi Kepolisian / Medis',
        'Pembersihan Lajur dari Puing',
    ];

    public function up(): void
    {
        $this->check(config('enums.action'));
    }

    public function down(): void
    {
        $this->check(self::TINDAKAN_LAMA, ' NOT VALID');
    }

    private function check(array $daftar, string $akhiran = ''): void
    {
        $actions = DB::getPdo()->quote(json_encode($daftar));

        DB::statement('ALTER TABLE incident_report DROP CONSTRAINT incident_report_action_check');
        // `action` WAJIB dikutip — sama alasannya dengan migration pembuatnya.
        DB::statement("ALTER TABLE incident_report ADD CONSTRAINT incident_report_action_check CHECK (\"action\" IS NULL OR (jsonb_typeof(\"action\") = 'array' AND \"action\" <@ {$actions}::jsonb)){$akhiran}");
    }
};
