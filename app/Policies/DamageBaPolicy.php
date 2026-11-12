<?php

namespace App\Policies;

use App\Models\DamageBa;
use App\Models\User;

/**
 * Sama seperti IncidentBaPolicy, satu tingkat lebih dalam: damage_ba tidak
 * menyimpan pemiliknya sendiri, cuma FK ke incident_ba.
 */
class DamageBaPolicy
{
    public function view(User $user, DamageBa $damageBa): bool
    {
        return (new IncidentBaPolicy)->view($user, $damageBa->incidentBa);
    }
}
