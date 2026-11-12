<?php

namespace App\Http\Controllers\Api\Mcs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CorrectIncidentReportRequest;
use App\Http\Requests\Api\RejectDamageBaRequest;
use App\Http\Requests\Api\RejectIncidentBaRequest;
use App\Http\Requests\Api\UploadReliefLetterRequest;
use App\Http\Requests\Api\ValidateIncidentBaRequest;
use App\Models\IncidentReport;
use App\Services\Mcs\IncidentVerificationService;
use Illuminate\Http\Request;

/**
 * Fase 2 — aksi MCS Supervisor. Terpisah dari IncidentReportController yang milik
 * Petugas Lapangan: digabung, batas role-nya berhenti terlihat dari nama berkas.
 *
 * Semua route di sini dijaga `role:mcs_supervisor` (allowlist). Tidak ada Policy —
 * Policy menjawab "punya siapa", dan MCS tidak memiliki laporan mana pun; yang
 * membatasinya role, lalu status laporan yang dijaga Service dengan 422.
 */
class IncidentVerificationController extends Controller
{
    public function __construct(private readonly IncidentVerificationService $verification) {}

    /**
     * Sahkan BA Kejadian. Di sinilah nomor laporan dan nomor BA Kejadian terbit.
     */
    public function validateIncidentBa(ValidateIncidentBaRequest $request, IncidentReport $incidentReport): array
    {
        return [
            'incident_ba' => $this->verification->validateIncidentBa(
                $incidentReport, $request->user(), $request->validated(),
            ),
            // Status laporannya ikut, dan ini bentuk yang DISENGAJA: tanpa ini layar
            // MCS harus memanggil ulang detail cuma untuk tahu laporannya sekarang
            // masih mengantre atau sudah verified. Empat kolom, bukan barisnya utuh.
            'report' => $incidentReport->refresh()->only(['uuid', 'report_number', 'report_status', 'is_asset_broken']),
            'message' => 'BA Kejadian disahkan.',
        ];
    }

    /**
     * Tolak BA Kejadian — kronologi atau pihak terlibat yang harus diperbaiki.
     */
    public function rejectIncidentBa(RejectIncidentBaRequest $request, IncidentReport $incidentReport): array
    {
        return [
            'incident_ba' => $this->verification->rejectIncidentBa(
                $incidentReport, $request->user(), $request->validated(),
            ),
            'report' => $incidentReport->refresh()->only(['uuid', 'report_number', 'report_status', 'is_asset_broken']),
            'message' => 'BA Kejadian ditolak dan laporan dikembalikan ke petugas.',
        ];
    }

    /**
     * Sahkan BA Kerusakan. Tidak menerima body — nilainya dihitung server.
     */
    public function validateDamageBa(Request $request, IncidentReport $incidentReport): array
    {
        return [
            'damage_ba' => $this->verification->validateDamageBa($incidentReport, $request->user()),
            'report' => $incidentReport->refresh()->only(['uuid', 'report_number', 'report_status', 'is_asset_broken']),
            'message' => 'BA Kerusakan disahkan.',
        ];
    }

    /**
     * Tolak BA Kerusakan — kalkulasi volume atau jenis aset yang harus diperbaiki.
     * BA Kejadian yang sudah disahkan tidak ikut dibatalkan.
     */
    public function rejectDamageBa(RejectDamageBaRequest $request, IncidentReport $incidentReport): array
    {
        return [
            'damage_ba' => $this->verification->rejectDamageBa(
                $incidentReport, $request->user(), $request->validated(),
            ),
            'report' => $incidentReport->refresh()->only(['uuid', 'report_number', 'report_status', 'is_asset_broken']),
            'message' => 'BA Kerusakan ditolak dan laporan dikembalikan ke petugas.',
        ];
    }

    /**
     * Koreksi lokasi dan volume sebelum pengesahan. Tercatat di activity_logs.
     */
    public function correct(CorrectIncidentReportRequest $request, IncidentReport $incidentReport): array
    {
        return [
            'report' => $this->verification->correct(
                $incidentReport, $request->user(), $request->validated(),
            ),
            'message' => 'Koreksi tersimpan.',
        ];
    }

    /**
     * Lampirkan surat permohonan keringanan atas nama MCS. Jenis dokumennya dipaku
     * server — lihat UploadReliefLetterRequest.
     */
    public function uploadReliefLetter(UploadReliefLetterRequest $request, IncidentReport $incidentReport): array
    {
        return [
            'document' => $this->verification->uploadReliefLetter(
                $incidentReport,
                $request->file('file'),
                $request->validated('file_uuid'),
                $request->user(),
            ),
        ];
    }

    /**
     * Susun link wa.me untuk meneruskan permohonan keringanan.
     *
     * POST, bukan GET, karena memang menulis: satu baris activity_logs yang jadi
     * satu-satunya jejak bahwa eskalasi pernah dilakukan. wa.me cuma MEMBUKA
     * WhatsApp — sistem tidak akan pernah tahu pesannya terkirim.
     */
    public function escalationLink(Request $request, IncidentReport $incidentReport): array
    {
        return [
            'wa_url' => $this->verification->escalationLink($incidentReport, $request->user()),
        ];
    }
}
