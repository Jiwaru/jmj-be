<?php

namespace App\Http\Controllers\Api\Mcs;

use App\Http\Controllers\Controller;
use App\Models\DamageBa;
use App\Models\IncidentBa;
use App\Models\IncidentReport;
use App\Services\Mcs\BaPdfService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Menyajikan PDF kedua Berita Acara.
 *
 * SATU endpoint per BA, DUA perilaku — lihat BaPdfService: yang belum disahkan
 * dirender saat itu juga dan bertanda DRAFT, yang sudah disahkan distream dari disk
 * apa adanya dan bytes-nya tidak pernah berubah lagi.
 *
 * Dibuka untuk `field_officer` (permintaan tim FE): petugas boleh lihat PDF BA
 * laporannya sendiri, MCS Supervisor lihat semuanya — IncidentBaPolicy/DamageBaPolicy
 * yang menjaga, role di route cuma gerbang kasar (pola sama dengan DocumentPolicy).
 */
class BaPdfController extends Controller
{
    public function __construct(private readonly BaPdfService $pdf) {}

    public function incidentBa(IncidentBa $incidentBa): Response|StreamedResponse
    {
        Gate::authorize('view', $incidentBa);

        return $this->pdf->download($incidentBa);
    }

    public function damageBa(DamageBa $damageBa): Response|StreamedResponse
    {
        Gate::authorize('view', $damageBa);

        return $this->pdf->download($damageBa);
    }

    /** Dari uuid laporan — bisa sebelum ACC MCS, lihat BaPdfService::preview(). */
    public function incidentBaPreview(IncidentReport $incidentReport): Response|StreamedResponse
    {
        Gate::authorize('view', $incidentReport);

        return $this->pdf->preview($incidentReport, 'incident');
    }

    public function damageBaPreview(IncidentReport $incidentReport): Response|StreamedResponse
    {
        Gate::authorize('view', $incidentReport);

        return $this->pdf->preview($incidentReport, 'damage');
    }
}
