<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * location_type: snake_case Inggris -> bahasa Indonesia, dan 'ramp' dipecah dua.
 *
 * Dua sebab, keduanya ketahuan waktu mencetak Berita Acara (2 Sep):
 *
 *   1. Nilainya tercetak MENTAH di slot "/ on/off ramp ......" — 'toll_gate' muncul
 *      apa adanya di dokumen resmi yang dipakai menagih pengguna jalan.
 *   2. 'ramp' tidak pernah bisa mengisi slot itu. Formulirnya minta on ATAU off;
 *      satu nilai gabungan tidak menjawabnya, dan unit maintenance yang dikirim ke
 *      titik yang salah baru tahu setelah sampai.
 *
 * Migration create-nya SUDAH ikut diperbaiki (default + daftar CHECK dibaca dari
 * config), jadi database baru langsung benar. Yang ini untuk database yang terlanjur
 * dimigrasi — dev dan NAS.
 */
return new class extends Migration
{
    /** Tabel => nama constraint CHECK-nya. Namanya beda, bukan salah ketik. */
    private const TABEL = [
        'incident_report' => 'incident_report_location_type_check',
        'work_orders' => 'work_orders_location_check',
    ];

    public function up(): void
    {
        $this->pindah([
            'mainline' => 'Jalur Utama',
            // 'ramp' TIDAK bisa dipecah otomatis jadi masuk/keluar: arahnya memang
            // tidak pernah direkam. Dipetakan ke 'Ramp Keluar' karena waktu migrasi
            // ini ditulis barisnya nol di produksi — satu baris seeder di dev dan
            // work_orders masih kosong. Kalau ternyata ada baris sungguhan, yang
            // benar membetulkannya satu per satu, bukan menebak massal.
            'ramp' => 'Ramp Keluar',
            'toll_gate' => 'Gerbang Tol',
            'rest_area' => 'Rest Area',
        ], config('enums.location_type'), 'Jalur Utama');
    }

    public function down(): void
    {
        $this->pindah([
            'Jalur Utama' => 'mainline',
            'Ramp Masuk' => 'ramp',
            'Ramp Keluar' => 'ramp',
            'Gerbang Tol' => 'toll_gate',
            'Rest Area' => 'rest_area',
        ], ['mainline', 'ramp', 'toll_gate', 'rest_area'], 'mainline');
    }

    /**
     * CHECK dilepas dulu, baru barisnya dipindah, baru CHECK dipasang lagi —
     * urutan yang lain ditolak constraint-nya sendiri di tengah jalan.
     */
    private function pindah(array $peta, array $nilai, string $default): void
    {
        $in = collect($nilai)->map(fn ($s) => DB::getPdo()->quote($s))->implode(',');

        foreach (self::TABEL as $tabel => $check) {
            DB::statement("ALTER TABLE {$tabel} DROP CONSTRAINT {$check}");
            DB::statement("ALTER TABLE {$tabel} ALTER COLUMN location_type DROP DEFAULT");

            foreach ($peta as $lama => $baru) {
                DB::table($tabel)->where('location_type', $lama)->update(['location_type' => $baru]);
            }

            DB::statement("ALTER TABLE {$tabel} ALTER COLUMN location_type SET DEFAULT ".DB::getPdo()->quote($default));
            DB::statement("ALTER TABLE {$tabel} ADD CONSTRAINT {$check} CHECK (location_type IN ({$in}))");
        }
    }
};
