<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Isian modal "Sahkan BA Kejadian" — cuma `duty_period`.
 *
 * PENANDA TANGAN TIDAK DI SINI SEJAK REDESAIN 16 SEP. Dulu MCS mengetik
 * `officer_1_id`/`officer_2_id` di sini; sekarang keduanya sudah ditentukan
 * petugas sendiri saat submit laporan (`SyncIncidentReportRequest`) —
 * `IncidentVerificationService` menyalinnya dari `incident_report`, bukan dari
 * body request ini. Alasan pemisahannya tetap sama seperti dulu: pivot
 * `operational_team` adalah fakta operasional siapa HADIR di TKP (bisa empat
 * orang), BA adalah fakta hukum siapa yang MENANDATANGANI (tepat dua) — cuma
 * sekarang yang menjembatani dua fakta itu petugas pelapor, bukan MCS. MCS
 * tetap menandatangani BA lewat aksi Sahkan itu sendiri (SK menyebut MCS
 * Supervisor sebagai salah satu penanda tangan), bukan lewat mengetik nama.
 */
class ValidateIncidentBaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'duty_period' => ['nullable', 'string', 'max:60'],
            // `shift` SENGAJA TIDAK DI SINI. Diturunkan server dari
            // incident_report.date_time lewat config('toll.shifts') — shift itu fungsi
            // dari jam kejadian, jadi menerimanya dari MCS cuma menambah satu tempat
            // yang bisa melenceng dari kolom di sebelahnya. Kiriman yang tetap memuat
            // `shift` diabaikan, bukan ditolak: layar lama tidak perlu ikut dirilis.
            //
            // `duty_period` TETAP diketik MCS: kalimatnya bebas ("Shift 2 - 14:00 s.d.
            // 22:00") dan tidak punya kolom mana pun yang bisa menurunkannya.
        ];
    }
}
