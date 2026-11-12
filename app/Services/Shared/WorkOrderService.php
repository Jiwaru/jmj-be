<?php

namespace App\Services\Shared;

use App\Exceptions\DomainException;
use App\Models\ActivityLog;
use App\Models\DamagedItem;
use App\Models\IncidentReport;
use App\Models\OperationalTeam;
use App\Models\PatrolReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderReview;
use App\Notifications\IncidentReportNotification;
use App\Notifications\WorkOrderNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Fase 4 dan Fase 6 — apa yang boleh dilakukan Unit Maintenance terhadap satu pekerjaan.
 *
 * SATU WORK ORDER = SATU TITIK LOKASI, bukan satu jenis aset. Satu WO menutup semua baris
 * `damaged_items` di titik itu lewat `damaged_items.work_order_id`: satu regu, satu
 * kunjungan, satu aliran log, satu evaluasi. Regunya diwakili baris-baris
 * `operational_team`, bukan satu kolom `work_team_id` — kolom itu sudah dibuang dari skema.
 *
 * PEMICUNYA VALIDASI BA KERUSAKAN, BUKAN TERBITNYA TAGIHAN. Ini keputusan alur paling
 * menentukan di sistem ini dan paling mudah "dibetulkan" jadi salah: kalau WO dipasang ke
 * surat ganti rugi, kerusakan tabrak lari tidak akan pernah jadi pekerjaan — asetnya rusak,
 * tapi tidak ada pihak yang bisa ditagih, jadi tidak ada surat yang terbit. SK Pasal 3 ayat 4
 * malah mewajibkan sebaliknya: perbaikan segera dilaksanakan tanpa menunggu ganti rugi.
 */
class WorkOrderService
{
    public function __construct(private readonly NumberGenerator $numbers) {}

    /**
     * Terbitkan Work Order. Dua cabang, satu pintu — lihat StoreWorkOrderRequest.
     */
    public function issue(User $unit, array $data): WorkOrder
    {
        return DB::transaction(function () use ($unit, $data) {
            $workOrder = $data['trigger_source'] === WorkOrder::TRIGGER_INSIDEN
                ? $this->dariInsiden($unit, $data)
                : $this->manual($unit, $data);

            $this->tulisRegu($workOrder, $data['assignee_ids'], $data['coordinator_id'] ?? null);
            $this->beriTahuDitugaskan($workOrder, $data['assignee_ids']);

            // refresh(), bukan model hasil create() apa adanya: Eloquent cuma menyimpan
            // atribut yang IKUT DITULIS, jadi kolom yang sengaja dibiarkan kosong
            // (shift, warranty_status, cost_amount, trigger_note) hilang sama sekali dari
            // JSON-nya. Akibatnya satu Work Order punya dua bentuk — yang baru terbit
            // kekurangan empat kunci dibanding yang dibaca ulang lewat GET, dan FE yang
            // membaca `shift` meledak persis di response yang paling sering dipakai.
            return $workOrder->refresh();
        });
    }

    /**
     * Ganti seluruh regu pelaksana. Menghapus lalu menulis, bukan menambah — layarnya
     * mengirim daftar akhir, bukan selisihnya.
     */
    public function replaceTeam(WorkOrder $workOrder, array $assigneeIds, ?int $coordinatorId = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $assigneeIds, $coordinatorId) {
            $workOrder = $this->lock($workOrder);

            if (! in_array($workOrder->repair_status, WorkOrder::TEAM_EDITABLE_STATUSES, true)) {
                throw new DomainException(
                    'Work Order ini sedang dievaluasi atau sudah selesai, regunya tidak bisa diganti lagi.',
                    'repair_status',
                );
            }

            // Koordinator TERKUNCI begitu tugasnya diterima (keputusan PO 5 Okt): dia cuma
            // berganti lewat oper shift yang ditulisnya sendiri (WorkOrderLogService). Ganti
            // regu boleh menambah/mengganti teknisi, tapi tidak boleh mengeluarkan atau
            // mengganti koordinatornya. Tidak disebut = dipertahankan.
            if ($workOrder->repair_status !== 'new') {
                $koordinator = $workOrder->coordinatorId();

                if (($coordinatorId !== null && $coordinatorId !== $koordinator)
                    || ($koordinator !== null && ! in_array($koordinator, array_map('intval', $assigneeIds), true))) {
                    throw new DomainException(
                        'Koordinator tidak bisa diganti atau dikeluarkan setelah tugas diterima. Koordinator hanya berganti lewat oper shift.',
                        'coordinator_id',
                    );
                }

                $coordinatorId = $koordinator;
            }

            $lama = $workOrder->operationalTeam()->pluck('user_id');
            $workOrder->operationalTeam()->delete();
            $this->tulisRegu($workOrder, $assigneeIds, $coordinatorId);

            // Cuma yang BARU masuk regu — sisanya sudah diberi tahu waktu ditunjuk.
            $this->beriTahuDitugaskan($workOrder, collect($assigneeIds)->diff($lama)->all());

            // Yang dikeluarkan juga diberi tahu: tanpa ini WO-nya hilang begitu saja dari
            // daftarnya, padahal mungkin dia sedang dalam perjalanan ke lokasi.
            Notification::send(
                User::whereIn('id', $lama->diff($assigneeIds)->all())->get(),
                new WorkOrderNotification($workOrder, WorkOrderNotification::REMOVED, "Anda tidak lagi ditugaskan di Work Order {$workOrder->reference_number}."),
            );

            return $workOrder;
        });
    }

    /**
     * Fase 6 — periksa bukti yang diajukan regu, lalu tutup atau kembalikan.
     *
     * MENAMBAH BARIS, TIDAK PERNAH MENIMPA. Putaran evaluasi pertama tetap tersimpan:
     * berapa kali suatu pekerjaan ditolak justru angka paling berguna untuk menilai mutu
     * regu, dan menimpa menghapusnya tanpa jejak.
     */
    public function review(WorkOrder $workOrder, User $unit, array $data): WorkOrderReview
    {
        return DB::transaction(function () use ($workOrder, $unit, $data) {
            $workOrder = $this->lock($workOrder);

            if (! in_array($workOrder->repair_status, WorkOrder::REVIEWABLE_STATUSES, true)) {
                throw new DomainException(
                    'Work Order ini belum diajukan selesai oleh regu, jadi belum ada yang bisa dievaluasi.',
                    'repair_status',
                );
            }

            $diterima = $data['outcome'] === WorkOrderReview::OUTCOME_DITERIMA;

            // Biayanya dihitung SEBELUM baris review ditulis: kalau WO manual datang tanpa
            // cost_amount, yang benar adalah nol baris tersimpan dan 422 berpesan — bukan
            // evaluasi yang tercatat diterima sementara WO-nya gagal ditutup.
            $biaya = $diterima ? $this->biayaPenutupan($workOrder, $data['cost_amount'] ?? null) : null;

            $review = WorkOrderReview::create([
                'work_order_id' => $workOrder->id,
                'reviewer_id' => $unit->id,
                'meets_quality_standard' => $data['meets_quality_standard'],
                'quantity_matches_log' => $data['quantity_matches_log'],
                'outcome' => $data['outcome'],
                'rejection_notes' => $data['rejection_notes'] ?? null,
                'revision_deadline' => $data['revision_deadline'] ?? null,
                'reviewed_at' => now(),
            ]);

            Notification::send(
                $workOrder->operationalTeam()->with('user')->get()->pluck('user'),
                $diterima
                    ? new WorkOrderNotification($workOrder, WorkOrderNotification::ACCEPTED, "Work Order {$workOrder->reference_number} diterima dan ditutup.")
                    : new WorkOrderNotification($workOrder, WorkOrderNotification::RETURNED, "Work Order {$workOrder->reference_number} dikembalikan ({$data['outcome']}): {$data['rejection_notes']}"),
            );

            if ($diterima) {
                $workOrder->update([
                    'repair_status' => 'completed',
                    'closed_by' => $unit->id,
                    'closed_at' => now(),
                    'cost_amount' => $biaya,
                ]);

                $this->tutupLaporanKalauTuntas($workOrder, $unit);
            } else {
                // Alur 6.2a: dikembalikan ke `in_progress`, bukan ke `rework`. Nilai
                // `rework` ada di enum repair_status tapi tidak pernah dipakai — sama
                // seperti validation_status `submitted` yang juga mati sejak pengesahan BA
                // lahir langsung sebagai validated/rejected. Jangan dikarang artinya di
                // sini; yang membedakan jenis penolakan adalah work_order_reviews.outcome.
                $workOrder->update(['repair_status' => 'in_progress']);
            }

            return $review;
        });
    }

    /**
     * WO dari insiden — lokasi dan komponennya DIBACA dari laporan, tidak diketik ulang.
     */
    private function dariInsiden(User $unit, array $data): WorkOrder
    {
        $report = IncidentReport::lockForUpdate()->findOrFail($data['incident_report_id']);

        // Pemicunya validasi BA Kerusakan. Dijawab 422 berpesan, bukan dibiarkan mati di
        // FK violation: damage_minutes_id itu FK ke damage_ba.uuid, dan barisnya memang
        // belum ada selama BA-nya belum diputuskan.
        $damageBa = $report->incidentBa?->damageBa;

        if ($damageBa?->validation_status !== 'validated') {
            throw new DomainException(
                'BA Kerusakan laporan ini belum disahkan, jadi kerusakannya belum masuk antrean pekerjaan.',
                'incident_report_id',
            );
        }

        $items = $report->damagedItems()->whereNull('work_order_id')->get();

        // Tanpa penjaga ini terbit WO yang menutup NOL baris kerusakan: nomornya terpakai,
        // regunya ditugaskan, dan tidak ada satu pun komponen yang harus dikerjakan.
        if ($items->isEmpty()) {
            throw new DomainException(
                'Semua kerusakan di laporan ini sudah punya Work Order.',
                'incident_report_id',
            );
        }

        $workOrder = WorkOrder::create([
            'reference_number' => $this->numbers->next('work_order'),
            'incident_report_id' => $report->uuid,
            'damage_minutes_id' => $damageBa->uuid,
            // Sengaja NULL. Komponennya dibaca dari damaged_items; mengisi kolom ini juga
            // berarti dua sumber kebenaran yang bisa saling bertentangan, dan yang mana
            // yang benar baru ketahuan setelah regu sampai di lokasi.
            'asset_catalog_id' => null,
            'trigger_source' => WorkOrder::TRIGGER_INSIDEN,
            'priority' => $data['priority'],
            'damage_description' => $data['damage_description'] ?? $this->ringkasKerusakan($report, $items),
            // Lokasi DISALIN, bukan diminta. incident_report.location_type ada sejak 1 Sep
            // justru untuk ini: sebelum ada kolomnya, Alur 4.2 mengisi WO dengan 'mainline'
            // begitu saja, dan insiden di ramp keluar mengirim regu ke jalur utama.
            'location_type' => $report->location_type ?? 'Jalur Utama',
            'km' => $report->km,
            'm' => $report->m,
            'total_m' => $this->totalM($report->km, $report->m),
            'route' => $report->route,
            'lane' => $report->lane,
            'latitude' => $report->latitude,
            'longitude' => $report->longitude,
            // shift DIBIARKAN KOSONG, dan itu bukan kelalaian. Shift laporan adalah shift
            // saat KEJADIAN; shift yang mengerjakan perbaikan belum diketahui waktu WO
            // terbit dan bisa lebih dari satu (work_order_logs.shift yang mencatatnya).
            // Menyalin shift kejadian ke sini cuma memberi angka yang artinya salah.
            'repair_status' => 'new',
            'target_date' => $data['target_date'],
            'created_by' => $unit->id,
        ]);

        // Semua sekaligus, sesuai Alur 4.2: satu laporan = satu titik lokasi, jadi tidak ada
        // alasan komponen di titik yang sama dikerjakan dua kunjungan.
        DamagedItem::whereIn('id', $items->pluck('id'))->update(['work_order_id' => $workOrder->id]);

        return $workOrder;
    }

    /**
     * WO manual — temuan patroli, aus normal, preventif terjadwal.
     *
     * Tidak melalui Fase 1-3 sama sekali: tidak ada BA, tidak ada penagihan, biayanya
     * ditanggung Perusahaan. SK tidak mengatur jalur ini.
     *
     * `patrol_report_id` cuma passthrough — TIDAK membaca ulang asset_catalog_id/lokasi
     * dari laporannya (beda dengan dariInsiden()). Unit Maintenance tetap mengetik
     * sendiri field manual di bawah; FE yang mem-prefill dari GET /patrol-reports/{uuid}.
     * Alasannya: menghindari cabang ketiga di StoreWorkOrderRequest yang sudah punya dua.
     */
    private function manual(User $unit, array $data): WorkOrder
    {
        $patrol = null;

        if (! empty($data['patrol_report_id'])) {
            $patrol = PatrolReport::lockForUpdate()->findOrFail($data['patrol_report_id']);

            // Satu laporan patroli = satu Work Order. Tanpa penjaga ini dua penerbitan
            // beruntun untuk laporan yang sama menghasilkan dua WO yang menugaskan dua
            // regu ke titik yang sama.
            if ($patrol->isLinkedToWorkOrder()) {
                throw new DomainException(
                    'Laporan patroli ini sudah punya Work Order.',
                    'patrol_report_id',
                );
            }
        }

        return WorkOrder::create([
            'reference_number' => $this->numbers->next('work_order'),
            'incident_report_id' => null,
            'damage_minutes_id' => null,
            'patrol_report_id' => $data['patrol_report_id'] ?? null,
            // Kebalikan dari cabang insiden: WAJIB, karena tidak ada damaged_items untuk
            // dibaca. Yang dijaga FormRequest, yang ditulis di sini.
            'asset_catalog_id' => $data['asset_catalog_id'],
            'trigger_source' => $data['trigger_source'],
            'trigger_note' => $data['trigger_note'] ?? null,
            'priority' => $data['priority'],
            'damage_description' => $data['damage_description'],
            'location_type' => $data['location_type'],
            'km' => $data['km'],
            'm' => $data['m'] ?? null,
            'total_m' => $this->totalM($data['km'], $data['m'] ?? null),
            'route' => $data['route'],
            'lane' => $data['lane'] ?? null,
            // Titik peta: yang dikirim Unit menang, kalau tidak ada dari GPS laporan
            // patrolinya. Sepasang, tidak dicampur dari dua sumber.
            'latitude' => isset($data['latitude']) ? $data['latitude'] : $patrol?->latitude,
            'longitude' => isset($data['latitude']) ? $data['longitude'] : $patrol?->longitude,
            // cost_amount sengaja kosong (Alur 4.3): laporannya cuma "lampu KM 45+200
            // mati", angkanya baru muncul setelah regu menghitung. Mewajibkannya di sini
            // akan memacetkan setiap temuan patroli di tahap pembuatan.
            'cost_amount' => null,
            'repair_status' => 'new',
            'target_date' => $data['target_date'],
            'created_by' => $unit->id,
        ]);
    }

    private function tulisRegu(WorkOrder $workOrder, array $assigneeIds, ?int $coordinatorId = null): void
    {
        // Regu satu orang: dia koordinatornya (keputusan PO 5 Okt) — tidak perlu disebut.
        if ($coordinatorId === null && count($assigneeIds) === 1) {
            $coordinatorId = (int) array_values($assigneeIds)[0];
        }

        foreach ($assigneeIds as $userId) {
            OperationalTeam::create([
                'user_id' => $userId,
                'is_coordinator' => $coordinatorId !== null && (int) $userId === $coordinatorId,
                // CHECK num_nonnulls(incident_report_id, work_order_id) = 1 — baris regu
                // perbaikan menunjuk WO, TIDAK ikut menunjuk laporannya walau ada.
                'incident_report_id' => null,
                'work_order_id' => $workOrder->id,
            ]);
        }
    }

    private function biayaPenutupan(WorkOrder $workOrder, float|int|string|null $dikirim): string
    {
        $adaItem = $workOrder->damagedItems()->exists();

        if ($adaItem) {
            if ($dikirim !== null) {
                throw new DomainException(
                    'Biaya Work Order dari insiden dihitung dari daftar kerusakan yang sudah disahkan di BA, tidak diisi manual.',
                    'cost_amount',
                );
            }

            return (string) $workOrder->damagedItems()->sum('subtotal');
        }

        if ($dikirim === null) {
            throw new DomainException(
                'Isi biaya perbaikan sebelum menutup Work Order — pekerjaan ini tidak berasal dari insiden, jadi tidak ada angka yang bisa diturunkan.',
                'cost_amount',
            );
        }

        // Dibulatkan seperti subtotal dan additional_cost — uang di sistem ini tidak
        // mengenal sen, dan angka yang diketik tangan pintu terakhirnya.
        return (string) round((float) $dikirim);
    }

    private function tutupLaporanKalauTuntas(WorkOrder $workOrder, User $unit): void
    {
        $report = $workOrder->incidentReport;

        // WO manual tidak punya laporan — tidak ada yang perlu ditutup.
        if ($report === null || $report->report_status === 'closed') {
            return;
        }

        $adaSisaKerusakan = $report->damagedItems()->whereNull('work_order_id')->exists();
        $adaPekerjaanBerjalan = $report->workOrders()
            ->whereIn('repair_status', WorkOrder::OPEN_STATUSES)
            ->exists();

        if ($adaSisaKerusakan || $adaPekerjaanBerjalan) {
            return;
        }

        $sebelum = $report->report_status;
        $report->update(['report_status' => 'closed']);

        // Satu-satunya jejak bahwa laporan ini ditutup DARI SINI. Penutupan WO sendiri
        // sudah punya kolomnya (closed_by, closed_at), jadi tidak dicatat ulang — yang
        // masuk activity_logs justru yang tidak punya kolom, dan report_status berubah
        // gara-gara aksi di tabel lain adalah persis itu.
        ActivityLog::record(
            $report,
            'report_closed',
            $unit,
            ['report_status' => $sebelum],
            ['report_status' => 'closed'],
            "Laporan ditutup setelah Work Order {$workOrder->reference_number} selesai dievaluasi.",
        );

        User::find($report->user_id)?->notify(new IncidentReportNotification(
            $report,
            IncidentReportNotification::CLOSED,
            'Semua kerusakan di '.IncidentReportNotification::label($report).' sudah selesai diperbaiki. Laporan ditutup.',
        ));
    }

    private function ringkasKerusakan(IncidentReport $report, $items): string
    {
        $jenis = $items->first()?->assetCatalog?->assetType?->name ?? 'aset';

        return sprintf(
            'Perbaikan %s KM %s+%s arah %s%s — %d komponen',
            $jenis,
            $report->km ?? '-',
            $report->m !== null ? str_pad((string) $report->m, 3, '0', STR_PAD_LEFT) : '000',
            $report->route ?? '-',
            $report->lane ? ' '.$report->lane : '',
            $items->count(),
        );
    }

    private function beriTahuDitugaskan(WorkOrder $workOrder, array $userIds): void
    {
        Notification::send(
            User::whereIn('id', $userIds)->get(),
            new WorkOrderNotification($workOrder, WorkOrderNotification::ASSIGNED, "Work Order {$workOrder->reference_number} ditugaskan kepada Anda."),
        );
    }

    private function totalM(?int $km, ?int $m): ?int
    {
        return $km === null ? null : $km * 1000 + ($m ?? 0);
    }

    private function lock(WorkOrder $workOrder): WorkOrder
    {
        return WorkOrder::lockForUpdate()->findOrFail($workOrder->id);
    }
}
