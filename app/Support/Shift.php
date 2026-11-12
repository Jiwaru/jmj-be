<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Shift kerja diturunkan dari JAM, di satu tempat.
 *
 * Dipakai dua pemakai yang tidak saling kenal: `IncidentReport::shift()` (slot "( shift )"
 * di BA Kejadian, Fase 2) dan `WorkOrderLogService` (kolom `work_order_logs.shift`,
 * Fase 5). Diketik dua kali, yang pertama meleset duluan adalah shift malam.
 *
 * BATAS JAMNYA SENDIRI BELUM DIVERIFIKASI KANTOR — `config('toll.shifts')` diturunkan
 * dari SATU contoh di dokumen alur plus asumsi pola tiga shift delapan jam (Mentok #16).
 * Yang berubah kalau ternyata salah cuma angka di config; nol perubahan kode.
 */
class Shift
{
    /**
     * '1' | '2' | '3', atau null kalau jamnya tidak jatuh di rentang mana pun.
     *
     * Null itu jawaban yang sah, bukan kegagalan: di BA Kejadian slotnya lalu tercetak
     * "I /II/ III" seperti kertasnya dan dilingkari tangan. CHECK di kedua migration
     * memang cuma menerima ketiga angka itu atau NULL.
     */
    public static function at(?Carbon $waktu): ?string
    {
        if ($waktu === null) {
            return null;
        }

        $jam = (int) $waktu->format('G');

        foreach (config('toll.shifts') as $shift => $jamKerja) {
            // Shift malam melewati tengah malam (from > to), jadi rentangnya PECAH:
            // 22-06 berarti jam >= 22 ATAU jam < 6. Perbandingan tunggal
            // (jam >= 22 && jam < 6) tidak pernah benar untuk jam mana pun — dan itu
            // persis kesalahan yang akan lahir kalau logika ini disalin ulang.
            $cocok = $jamKerja['from'] < $jamKerja['to']
                ? $jam >= $jamKerja['from'] && $jam < $jamKerja['to']
                : $jam >= $jamKerja['from'] || $jam < $jamKerja['to'];

            if ($cocok) {
                return (string) $shift;
            }
        }

        return null;
    }
}
