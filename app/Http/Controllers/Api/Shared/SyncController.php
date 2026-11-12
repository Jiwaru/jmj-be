<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Services\Shared\OfflineBundleService;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(private readonly OfflineBundleService $bundle) {}

    /**
     * Bekal luring, DIBEDAKAN ROLE — satu alamat, dua muatan.
     *
     * Route-nya sengaja satu supaya tiap aplikasi punya satu langkah "unduh bekal" yang
     * sama bentuknya, pola yang sama dengan `incident-reports` dan `work-orders` yang
     * juga dipakai dua role. Yang berbeda isinya, dan bedanya besar: bundel patroli
     * berisi DATA ACUAN (katalog, harga, pilihan form), bundel maintenance cuma daftar
     * pilihan layar Fase 5 — pekerjaannya sendiri datang dari `GET /work-orders` yang
     * berpaginasi.
     *
     * Role yang tidak disebut (`mcs_supervisor`, `maintenance_unit`) tetap dapat bundel
     * patroli. Dua-duanya bekerja di web dalam intranet dan tidak pernah memanggil ini;
     * yang penting endpoint-nya tidak pernah menjawab kosong tanpa penjelasan.
     */
    public function bootstrap(Request $request): array
    {
        return $request->user()->role === 'maintenance_officer'
            ? $this->bundle->forMaintenanceOfficer()
            : $this->bundle->forFieldOfficer();
    }
}
