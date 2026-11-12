<?php

namespace App\Policies;

use App\Models\PatrolReport;
use App\Models\User;

/**
 * Menjawab "laporan patroli ini punya siapa" saja — mirror IncidentReportPolicy.
 *
 * Aturan status (sudah dipakai WO, tidak bisa diubah lagi) sengaja TIDAK di sini,
 * tapi di PatrolReportSyncService: 403 berarti "bukan punyamu", 422 berarti "punyamu,
 * tapi keadaannya tidak mengizinkan".
 */
class PatrolReportPolicy
{
    /**
     * maintenance_unit melihat laporan siapa pun — dia yang menindaklanjutinya jadi
     * Work Order lewat antrean patrol-queue. Petugas tetap cuma miliknya.
     */
    public function view(User $user, PatrolReport $report): bool
    {
        return $report->officer_id === $user->id || $user->role === 'maintenance_unit';
    }

    public function update(User $user, PatrolReport $report): bool
    {
        return $report->officer_id === $user->id;
    }
}
