<?php

namespace App\Policies;

use App\Models\IncidentReport;
use App\Models\User;

/**
 * Menjawab "laporan ini punya siapa" saja.
 *
 * Aturan status — laporan yang sudah disahkan tidak boleh diubah lagi — sengaja
 * TIDAK di sini, tapi di IncidentReportSyncService. Alasannya bentuk jawabannya:
 * 403 berarti "ini bukan punyamu", 422 berarti "punyamu, tapi keadaannya tidak
 * mengizinkan". Aplikasi mobile perlu membedakan keduanya untuk memilih pesan.
 */
class IncidentReportPolicy
{
    /**
     * MCS Supervisor melihat laporan siapa pun — dia yang memverifikasinya, dan
     * antreannya memang berisi laporan orang lain. Petugas tetap cuma miliknya.
     *
     * Allowlist, bukan `!== 'field_officer'`: role ke-5 (monitoring JMJ) yang sudah
     * diantisipasi tidak boleh mewarisi akses ke NIK dan foto KTP pihak ketiga di
     * hari pertama cuma karena kebetulan bukan field_officer.
     */
    public function view(User $user, IncidentReport $report): bool
    {
        return $report->user_id === $user->id || $user->role === 'mcs_supervisor';
    }

    public function update(User $user, IncidentReport $report): bool
    {
        return $report->user_id === $user->id;
    }
}
