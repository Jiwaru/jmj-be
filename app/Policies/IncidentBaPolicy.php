<?php

namespace App\Policies;

use App\Models\IncidentBa;
use App\Models\User;

/**
 * Delegasi murni ke IncidentReportPolicy — "boleh lihat BA Kejadian" sama dengan
 * "boleh lihat laporan insidennya", karena BA cuma potret laporan itu setelah
 * disahkan. Petugas cuma laporannya sendiri, MCS Supervisor semuanya.
 */
class IncidentBaPolicy
{
    public function view(User $user, IncidentBa $incidentBa): bool
    {
        return (new IncidentReportPolicy)->view($user, $incidentBa->incidentReport);
    }
}
