<?php

namespace App\Http\Controllers\Api\MaintenanceOfficer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SyncWorkOrderLogRequest;
use App\Http\Requests\Api\UploadWorkOrderPhotoRequest;
use App\Models\WorkOrder;
use App\Models\WorkOrderLog;
use App\Services\MaintenanceOfficer\WorkOrderLogService;
use Illuminate\Support\Facades\Gate;

/**
 * Fase 5 — aplikasi Petugas Maintenance menulis ke garis waktu pekerjaannya.
 *
 * PUNYA POLICY, beda dari WorkOrderController. Di Fase 4 dan 6 Work Order tidak dimiliki
 * siapa pun dan role saja cukup; di sini petugas punya WO **miliknya sendiri**, dan role
 * tidak bisa membedakan pekerjaannya dari pekerjaan regu sebelah.
 */
class WorkOrderLogController extends Controller
{
    public function __construct(private readonly WorkOrderLogService $logs) {}

    /**
     * Tulis satu baris log. `entry_type` yang menentukan aksinya.
     *
     * PUT dengan uuid yang lahir di HP, bukan POST — dan itu bukan selera REST. Di
     * aplikasi luring, "kirim" berarti sync, dan sync yang gagal di tengah akan diulang.
     * POST melahirkan baris kedua tiap kali sinyal putus; PUT ke uuid yang sama
     * mengembalikan baris yang sudah ada. Persis alasan `PUT /incident-reports/{uuid}`.
     */
    public function sync(SyncWorkOrderLogRequest $request, string $uuid): array
    {
        $workOrder = WorkOrder::findOrFail($request->validated('work_order_id'));
        Gate::authorize('log', $workOrder);

        $log = $this->logs->sync($uuid, $workOrder, $request->user(), $request->validated());

        return [
            'log' => $log,
            // repair_status ikut dikembalikan karena baris log BISA mengubahnya, dan
            // layarnya perlu tahu tombol mana yang sekarang hidup. Tanpa ini setiap
            // pencatatan harus disusul GET detail cuma untuk menanyakan hal yang baru
            // saja terjadi karena kiriman itu sendiri.
            'work_order' => $workOrder->refresh()->only([
                'id', 'reference_number', 'repair_status', 'received_by', 'handed_over_at',
            ]),
            'message' => self::PESAN[$log->entry_type] ?? 'Tercatat.',
        ];
    }

    /**
     * Unggah foto bukti ke satu baris log.
     *
     * Terpisah dari `sync()`, dan itu wajib untuk klien luring: baris log ditulis saat
     * kejadian (di bahu jalan, tanpa sinyal), fotonya menyusul saat sinyal ada. Digabung
     * jadi satu request multipart, baris log tidak bisa tersimpan sampai fotonya
     * terkirim — dan yang hilang justru jam kejadiannya.
     */
    public function uploadDocument(UploadWorkOrderPhotoRequest $request, WorkOrderLog $workOrderLog): array
    {
        Gate::authorize('log', $workOrderLog->workOrder);

        return [
            'document' => $this->logs->attachPhoto(
                $workOrderLog,
                $request->file('file'),
                $request->validated('file_uuid'),
                $request->user(),
            ),
        ];
    }

    private const PESAN = [
        'start' => 'Tugas diterima. Lengkapi verifikasi APD sebelum berangkat.',
        'apd_check' => 'APD terverifikasi. Pekerjaan dimulai.',
        'progress' => 'Progres tercatat.',
        'shift_handover' => 'Pekerjaan dioper ke shift berikutnya.',
        'obstacle' => 'Kendala dilaporkan. Pekerjaan tetap berjalan.',
        'complete' => 'Pekerjaan diajukan selesai, menunggu evaluasi Unit Maintenance.',
    ];
}
