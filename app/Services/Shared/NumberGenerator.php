<?php

namespace App\Services\Shared;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Penerbit nomor resmi: nomor laporan, nomor kedua Berita Acara, dan nomor Work Order.
 *
 * EMPAT urutan yang sendiri-sendiri (keputusan 1 Sep, ditambah Work Order 3 Sep). BA
 * Kerusakan cuma terbit kalau ada kerusakan, jadi urutannya selalu tertinggal di
 * belakang BA Kejadian; Work Order malah bisa terbit tanpa insiden sama sekali (temuan
 * patroli). Satu counter untuk berempat akan membuat tiap nomor melompat-lompat
 * mengikuti kejadian yang tidak ada hubungannya dengannya.
 *
 * DUA format, satu bentuk. Berita Acara memakai config('toll.number_format') —
 * 087/TM.JOGLO/VIII/2026, contoh nyata di SK. Work Order memakai
 * config('toll.wo_number_format') — 004/JMTM/TM.JOGLO/IX/2026, dibedakan supaya
 * perintah kerja dan Berita Acara tidak tertukar di kertas. Keduanya WAJIB diawali
 * angka urut dan diakhiri /{tahun}: itu dua pola yang dibaca query pemindai di bawah.
 *
 * Nomor TIDAK BOLEH dialokasikan HP (keputusan 28 Ags): dua petugas luring bisa
 * mengambil urutan yang sama, dan yang kedua nyangkut di unique constraint.
 */
class NumberGenerator
{
    /**
     * Peta counter ke tabel, kolom, dan KUNCI CONFIG format nomornya.
     *
     * Sengaja const di sini, BUKAN config: nilainya tidak akan pernah berubah tanpa
     * deploy, dan config untuk nilai yang tidak pernah berubah cuma menambah tempat
     * yang harus dicari orang. Sekalian jadi allowlist — nama tabel dan kolom di
     * query di bawah hanya boleh berasal dari sini, tidak pernah dari input.
     *
     * Yang disimpan elemen ketiga itu KUNCI config, bukan string formatnya: format
     * nomor tetap tinggal satu tempat di config/toll.php (KONVENSI bagian 5), dan di
     * sini cuma tertulis yang mana.
     */
    private const COUNTERS = [
        'report' => ['incident_report', 'report_number', 'toll.number_format'],
        'incident_ba' => ['incident_ba', 'incident_ba_number', 'toll.number_format'],
        'damage_ba' => ['damage_ba', 'damage_ba_number', 'toll.number_format'],
        // Work Order memakai format sendiri — 004/JMTM/TM.JOGLO/IX/2026. Bentuknya
        // sengaja masih diawali angka dan diakhiri /{tahun}, jadi pemindaian nomor
        // terakhir di bawah tidak perlu tahu ada dua format.
        'work_order' => ['work_orders', 'reference_number', 'toll.wo_number_format'],
    ];

    private const ROMAN = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    /**
     * Terbitkan nomor berikutnya untuk satu counter, di tahun $at.
     *
     * WAJIB dipanggil dari dalam transaksi yang sama dengan INSERT-nya. Kuncinya
     * advisory xact lock: dia lepas sendiri saat transaksi selesai, jadi di luar
     * transaksi dia lepas seketika dan tidak menjaga apa pun — dua supervisor yang
     * menekan Sahkan di detik yang sama akan dapat nomor yang sama, dan yang kedua
     * mati di unique constraint dengan pesan yang tidak menjelaskan apa-apa.
     *
     * TIDAK ADA TESTNYA, dan itu bukan kelalaian: `RefreshDatabase` membungkus setiap
     * test dalam transaksi, jadi `transactionLevel()` tidak pernah 0 di dalam test dan
     * cabang ini mustahil dicapai dari sana. Penjaganya tetap dipasang karena yang
     * dijaga terjadi di produksi, bukan di test.
     *
     * @throws LogicException dipanggil di luar transaksi
     */
    public function next(string $counter, ?CarbonInterface $at = null): string
    {
        [$table, $column, $format] = self::COUNTERS[$counter]
            ?? throw new LogicException("Counter nomor tidak dikenal: {$counter}");

        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'NumberGenerator::next() wajib dipanggil di dalam transaksi — '
                .'advisory lock di luar transaksi lepas seketika dan tidak menjaga apa pun.'
            );
        }

        $at ??= Carbon::now();
        $year = (int) $at->format('Y');

        // Kunci per (kolom, tahun): keempat counter tidak saling memblokir, dan
        // penerbitan tahun depan tidak menunggu tahun ini.
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["{$column}_{$year}"]);

        // substring(... from '^[0-9]+'), bukan split_part: yang tidak berpola
        // mengembalikan NULL dan diabaikan max(), bukan meledak di ::int. Nomor
        // ber-format lain tidak pernah ada — tapi kalau suatu hari ada, penerbitan
        // nomor bukan tempat yang benar untuk mengetahuinya lewat 500.
        $max = (int) DB::scalar(
            "SELECT coalesce(max(substring({$column} from '^[0-9]+')::int), 0) FROM {$table} WHERE {$column} LIKE ?",
            ["%/{$year}"]
        );

        return sprintf(
            config($format),
            $max + 1,
            self::ROMAN[(int) $at->format('n') - 1],
            $year,
        );
    }
}
