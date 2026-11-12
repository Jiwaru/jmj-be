<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListWorkOrderRequest;
use App\Http\Requests\Api\ReviewWorkOrderRequest;
use App\Http\Requests\Api\StoreWorkOrderRequest;
use App\Http\Requests\Api\UpdateWorkOrderTeamRequest;
use App\Models\WorkOrder;
use App\Models\WorkOrderReview;
use App\Services\Shared\WorkOrderService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Fase 4 dan Fase 6 — aksi Unit Maintenance terhadap Work Order.
 *
 * Route TULIS di sini dijaga `role:maintenance_unit` (allowlist) dan tidak punya Policy:
 * menerbitkan, menugaskan, dan mengevaluasi bukan soal "punya siapa" — yang membatasinya
 * role, lalu `repair_status` yang dijaga Service dengan 422. Alasannya sama persis dengan
 * IncidentVerificationController milik MCS.
 *
 * Route BACA (`index` dan `show`) berbeda sejak Fase 5: dipakai Unit Maintenance DAN
 * petugas maintenance, dan yang kedua cuma boleh melihat pekerjaan yang ditugaskan
 * kepadanya. Batasnya `WorkOrder::scopeVisibleTo()` untuk daftar dan `WorkOrderPolicy`
 * untuk detail — daftar yang disaring tanpa penjaga di detailnya cuma menyembunyikan
 * tautannya, bukan menutup pintunya.
 */
class WorkOrderController extends Controller
{
    public function __construct(private readonly WorkOrderService $workOrders) {}

    public function index(ListWorkOrderRequest $request): LengthAwarePaginator
    {
        return WorkOrder::query()
            // Petugas maintenance cuma melihat pekerjaan yang ditugaskan kepadanya;
            // Unit Maintenance melihat semuanya. Di model, bukan di controller — KONVENSI
            // melarang `if` bisnis di sini, dan ini fakta milik satu tabel.
            ->visibleTo($request->user())
            ->with([
                'operationalTeam.user:id,identity_code,name,position',
                'incidentReport:uuid,report_number,km,m,route,lane,location_type',
                'assetCatalog:id,name,component,unit_of_measurement',
                // Badge "Revisi" dan catatan evaluator di kartu dashboard. Tanpa ini FE
                // memanggil detail untuk SETIAP WO di daftar cuma demi satu badge.
                'reviews.reviewer:id,identity_code,name,position',
            ])
            ->withCount('damagedItems')
            ->when($request->validated('repair_status'), fn ($q, $v) => $q->whereIn('repair_status', $v))
            ->when($request->validated('priority'), fn ($q, $v) => $q->where('priority', $v))
            ->when($request->validated('trigger_source'), fn ($q, $v) => $q->where('trigger_source', $v))
            ->when($request->validated('route'), fn ($q, $v) => $q->where('route', $v))
            // Pola sama dengan IncidentReportController::index() — '!' wajib.
            ->when($request->validated('month'), fn ($q, $v) => $q->whereBetween('created_at', [
                $awal = Carbon::createFromFormat('!Y-m', $v),
                $awal->copy()->endOfMonth(),
            ]))
            ->latest('id')
            ->paginate(20);
    }

    /**
     * Detail satu Work Order — isinya harus cukup untuk layar evaluasi Fase 6 tanpa
     * panggilan kedua: komponen apa yang ditutup, siapa regunya, apa yang sudah dilaporkan
     * lapangan, dan berapa kali pekerjaan ini pernah dikembalikan.
     */
    public function show(WorkOrder $workOrder): array
    {
        // Route model binding tidak tahu apa-apa soal kepemilikan: tanpa baris ini
        // petugas bisa membuka pekerjaan regu sebelah cukup dengan menebak id-nya.
        Gate::authorize('view', $workOrder);

        $workOrder->load([
            'damagedItems.assetCatalog.assetType',
            'damagedItems.catalogPrice',
            'operationalTeam.user:id,identity_code,name,position',
            'assetCatalog.assetType',
            'creator:id,identity_code,name',
            'receiver:id,identity_code,name',
            'closer:id,identity_code,name',
            'incidentReport:uuid,report_number,date_time,km,m,route,lane,location_type,total_estimation',
            'incidentReport.incidentBa:uuid,incident_report_id,incident_ba_number',
            // logs.documents IKUT, dan itu bukan kemewahan: layar Activity Log
            // menampilkan foto DI DALAM tiap baris. Tanpa ini FE harus menembak satu
            // request per baris log cuma untuk tahu barisnya berfoto atau tidak.
            'logs.user:id,identity_code,name,position',
            'logs.handoverTo:id,identity_code,name,position',
            'logs.documents:id,documentable_id,document_type,mime_type,size_bytes,uploaded_at',
            'reviews.reviewer:id,identity_code,name,position',
        ]);

        return ['work_order' => $workOrder];
    }

    /**
     * Terbitkan Work Order — dari insiden maupun manual, satu endpoint.
     */
    public function store(StoreWorkOrderRequest $request): array
    {
        $workOrder = $this->workOrders->issue($request->user(), $request->validated());

        return [
            'work_order' => $workOrder->load([
                'operationalTeam.user:id,identity_code,name,position',
                'damagedItems.assetCatalog:id,name,component,unit_of_measurement',
            ]),
            'message' => "Work Order {$workOrder->reference_number} terbit.",
        ];
    }

    /**
     * Ganti regu pelaksana. Mengganti seluruhnya — layarnya mengirim daftar akhir.
     */
    public function updateTeam(UpdateWorkOrderTeamRequest $request, WorkOrder $workOrder): array
    {
        $workOrder = $this->workOrders->replaceTeam(
            $workOrder,
            $request->validated('assignee_ids'),
            $request->validated('coordinator_id'),
        );

        return [
            'work_order' => $workOrder->load('operationalTeam.user:id,identity_code,name,position'),
            'message' => 'Regu pelaksana diperbarui.',
        ];
    }

    /**
     * Fase 6 — evaluasi bukti, lalu tutup atau kembalikan ke regu.
     *
     * `work_order` ikut dikembalikan, dan itu bentuk yang DISENGAJA: `repair_status` dan
     * `cost_amount` dua-duanya berubah di sini, dan tanpa ini layar harus memanggil ulang
     * detail cuma untuk tahu pekerjaannya sekarang tertutup atau kembali berjalan.
     */
    public function review(ReviewWorkOrderRequest $request, WorkOrder $workOrder): array
    {
        $review = $this->workOrders->review($workOrder, $request->user(), $request->validated());

        return [
            'review' => $review,
            'work_order' => $workOrder->refresh()->only([
                'id', 'reference_number', 'repair_status', 'cost_amount', 'closed_at',
            ]),
            'report' => $workOrder->incidentReport?->only(['uuid', 'report_number', 'report_status']),
            'message' => $review->outcome === WorkOrderReview::OUTCOME_DITERIMA
                ? "Work Order {$workOrder->reference_number} ditutup."
                : 'Pekerjaan dikembalikan ke regu.',
        ];
    }
}
