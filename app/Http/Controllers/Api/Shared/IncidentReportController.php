<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListIncidentReportRequest;
use App\Http\Requests\Api\SyncIncidentReportRequest;
use App\Http\Requests\Api\UploadDocumentRequest;
use App\Models\DamageBa;
use App\Models\IncidentReport;
use App\Services\Shared\DocumentStorageService;
use App\Services\Shared\IncidentReportSyncService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class IncidentReportController extends Controller
{
    public function __construct(
        private readonly IncidentReportSyncService $sync,
        private readonly DocumentStorageService $documents,
    ) {}

    public function index(ListIncidentReportRequest $request): LengthAwarePaginator
    {
        // Filternya report_status, BUKAN sync_status. Di server tiap baris selalu
        // 'sent' — 'draft' dan 'queued' cuma hidup di database lokal HP, jadi
        // menyaringnya di sini akan selalu kosong. Tab Draft/Antre itu query lokal.
        //
        // Paginator dikembalikan utuh, bukan ->items(): tanpa current_page/last_page
        // aplikasi tidak punya cara tahu masih ada halaman berikutnya.
        // visibleTo(), bukan where('user_id'): route ini dipakai dua role. Petugas
        // melihat laporannya sendiri, MCS Supervisor melihat semuanya — dan `if`
        // rolenya duduk di model, bukan di sini (KONVENSI bagian 8).
        //
        // operationalTeam.user ikut karena kartu antrean MCS menampilkan "PETUGAS
        // PELAPOR: Budi Santoso & Agus Tri" — dua nama, jadi reporter saja kurang.
        return IncidentReport::visibleTo($request->user())
            ->with([
                'reporter:id,identity_code,name',
                'operationalTeam.user:id,identity_code,name',
                // Kartu list butuh tahu fase BA (Kejadian vs Kerusakan) tanpa fetch detail.
                // PK-nya uuid (bukan id) dan FK damage_ba ke incident_ba adalah
                // damage_report_id — lihat migration create_incident_bas/damage_bas_table.
                // rejection_reason ikut supaya card "Revisi/Ditolak" bisa menampilkan
                // alasan penolakan aslinya tanpa buka detail (gap dilaporkan tim FE).
                'incidentBa:uuid,incident_report_id,validation_status,incident_ba_number,rejection_reason',
                // validated_at ikut karena relief_deadline (accessor DamageBa) diturunkan
                // darinya — tanpa kolom ini tenggatnya diam-diam null di daftar.
                'incidentBa.damageBa:uuid,damage_report_id,validation_status,damage_ba_number,rejection_reason,validated_at',
                // full_name buat kolom "Pihak Terlibat" di card, party_role kalau perlu label peran.
                'culprits:id,incident_report_id,full_name,party_role',
                'officer2:id,identity_code,name',
            ])
            // queue MENGGANTIKAN report_status, bukan ditumpuk: awaiting_bar memang
            // memuat laporan 'rejected' (BAR ditolak), dan report_status=pending_verification
            // di atasnya diam-diam membuang baris itu — persis bug yang dilaporkan FE 1 Okt.
            ->when(
                $request->validated('queue'),
                fn ($q, $queue) => match ($queue) {
                    // Opt-in, BUKAN default: tanpa parameter tetap tanpa filter karena
                    // Daftar Laporan petugas memakai route ini dan chip "Terkirim"-nya
                    // memang harus memuat laporan yang sudah closed.
                    'active' => $q->where('report_status', '!=', 'closed'),
                    'awaiting_bak' => $q->awaitingIncidentBa(),
                    'awaiting_bar' => $q->awaitingDamageBa(),
                    // Tenggat terdekat dulu, bukan kejadian terbaru: di halaman Pantauan
                    // yang dikejar MCS adalah surat yang paling sebentar lagi lewat.
                    'awaiting_relief_letter' => $q->awaitingReliefLetter()->orderBy(
                        DamageBa::select('damage_ba.validated_at')
                            ->join('incident_ba', 'incident_ba.uuid', '=', 'damage_ba.damage_report_id')
                            ->whereColumn('incident_ba.incident_report_id', 'incident_report.uuid')
                            ->limit(1)
                    ),
                },
                fn ($q) => $q->when($request->validated('report_status'), fn ($q, $status) => $q->where('report_status', $status)),
            )
            // '!' wajib: tanpa itu hari ini ikut terbawa, dan "2026-02" yang diminta
            // tanggal 31 meluber ke Maret. whereBetween, bukan whereMonth, supaya
            // kolomnya tidak dibungkus fungsi.
            ->when($request->validated('month'), fn ($q, $month) => $q->whereBetween('date_time', [
                $awal = Carbon::createFromFormat('!Y-m', $month),
                $awal->copy()->endOfMonth(),
            ]))
            ->latest('date_time')
            ->paginate(20);
    }

    public function show(Request $request, string $uuid): array
    {
        $report = IncidentReport::findOrFail($uuid);
        Gate::authorize('view', $report);

        // rejection_reason ada di incident_ba, bukan di incident_report — petugas
        // baru bisa tahu kenapa laporannya ditolak lewat BA-nya.
        $report->load([
            'reporter:id,identity_code,name',
            // Penanda tangan BA Kejadian sudah ditentukan sejak submit (redesain 16
            // Sep) — MCS perlu melihat siapa keduanya SEBELUM klik Sahkan, bukan
            // memilihnya di sini. reporter di atas itu penanda tangan 1.
            'officer2:id,identity_code,name',
            'operationalTeam.user:id,identity_code,name',
            // culprits.involvedVehicle: satu kartu "Pihak N" di layar = satu pelaku
            // beserta kendaraannya, jadi detail laporan harus bisa menyusunnya ulang.
            'involvedVehicles', 'culprits.involvedVehicle', 'documents',
            // Nama komponen dan harga satuan untuk tabel "Rincian Kerusakan Aset" —
            // tanpa ini layar MCS cuma punya asset_catalog_id dan angka rupiah.
            'damagedItems.assetCatalog.assetType', 'damagedItems.catalogPrice',
            // Utuh, dan damageBa ikut: kartu "NOMOR BERITA ACARA" menampilkan status
            // KEDUA BA sekaligus, dan sejak pengesahannya dipisah keduanya memang
            // bisa berbeda — BAK sudah bernomor sementara BAR masih menunggu.
            // incident_ba tidak memuat data pribadi, jadi aman ikut ke petugas.
            'incidentBa.damageBa',
            // Kartu Otorisasi BA: "disahkan oleh SPV {nama}". validated_by juga diisi
            // saat DITOLAK, jadi validator = yang menolak untuk BA berstatus rejected.
            'incidentBa.validator:id,identity_code,name,position',
            'incidentBa.damageBa.validator:id,identity_code,name,position',
        ]);

        return ['report' => $report];
    }

    public function sync(SyncIncidentReportRequest $request, string $uuid): array
    {
        // Laporan baru boleh dibuat siapa pun ber-role field_officer; yang sudah ada
        // cuma boleh disentuh pemiliknya.
        if ($report = IncidentReport::find($uuid)) {
            Gate::authorize('update', $report);
        }

        return ['report' => $this->sync->upsert($uuid, $request->user(), $request->validated())];
    }

    public function uploadDocument(UploadDocumentRequest $request, string $uuid): array
    {
        $report = IncidentReport::findOrFail($uuid);
        Gate::authorize('update', $report);

        return [
            'document' => $this->documents->storeForIncident(
                $report,
                $request->file('file'),
                $request->validated('file_uuid'),
                $request->validated('document_type'),
                $request->user(),
                $request->validated('culprit_index'),
            ),
        ];
    }
}
