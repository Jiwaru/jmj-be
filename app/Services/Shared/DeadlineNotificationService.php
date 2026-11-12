<?php

namespace App\Services\Shared;

use App\Models\IncidentReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\IncidentReportNotification;
use App\Notifications\WorkOrderNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Notifikasi berbasis WAKTU — yang tidak dipicu aksi siapa pun, cuma jam yang berjalan.
 * Dijalankan scheduler tiap jam (routes/console.php, container `scheduler`).
 *
 * Sistem tetap cuma MEMANTAU: tidak ada status yang diubah, tidak ada eskalasi otomatis
 * (Mentok #15). Yang ditambahkan di sini hanya lonceng ke orang yang perlu tahu.
 *
 * Tiap jam jalan, jadi tiap notifikasi dijaga sudahDikirim() — tanpanya MCS menerima
 * "tenggat sudah lewat" yang sama 24 kali sehari. Penandanya tabel `notifications`
 * itu sendiri, bukan kolom baru: barisnya memang bukti bahwa notifikasi sudah terkirim.
 */
class DeadlineNotificationService
{
    /** @return array<string, int> jumlah subjek yang diberi tahu per jenis */
    public function run(): array
    {
        return [
            'relief_letter' => $this->tenggatSuratKeringanan(),
            'revision' => $this->tenggatRevisi(),
            'target' => $this->lewatTarget(),
        ];
    }

    /**
     * Surat permohonan keringanan yang belum masuk: sekali saat tinggal ≤ 24 jam, sekali
     * lagi saat sudah lewat. Ke semua MCS aktif — antrean Pantauan Masa Tenggang milik
     * mereka bersama.
     */
    private function tenggatSuratKeringanan(): int
    {
        $mcs = $this->aktif('mcs_supervisor');
        $jumlah = 0;

        foreach (IncidentReport::awaitingReliefLetter()->with('incidentBa.damageBa')->get() as $report) {
            $tenggat = $report->incidentBa->damageBa->relief_deadline;
            $label = IncidentReportNotification::label($report);

            [$event, $pesan] = match (true) {
                $tenggat->isPast() => [IncidentReportNotification::RELIEF_DEADLINE_PASSED,
                    "Tenggat surat permohonan keringanan {$label} sudah lewat ({$this->jam($tenggat)}), suratnya belum masuk."],
                $tenggat->lte(now()->addDay()) => [IncidentReportNotification::RELIEF_DEADLINE_NEAR,
                    "Tenggat surat permohonan keringanan {$label} tinggal kurang dari sehari ({$this->jam($tenggat)})."],
                default => [null, null],
            };

            if ($event === null || $this->sudahDikirim($event, 'incident_report_id', $report->uuid)) {
                continue;
            }

            Notification::send($mcs, new IncidentReportNotification($report, $event, $pesan));
            $jumlah++;
        }

        return $jumlah;
    }

    /**
     * Pekerjaan yang dikembalikan dengan target revisi, dan targetnya lewat sebelum regu
     * mengajukan selesai lagi. Ke regu (yang mengerjakan) dan Unit (yang menunggu).
     *
     * Dijaga per PUTARAN evaluasi, bukan per WO: pekerjaan yang dikembalikan dua kali
     * punya dua target revisi, dan yang kedua tetap harus berbunyi.
     */
    private function tenggatRevisi(): int
    {
        $jumlah = 0;

        $workOrders = WorkOrder::where('repair_status', 'in_progress')
            ->whereHas('reviews', fn ($q) => $q->where('revision_deadline', '<', now()))
            ->get();

        foreach ($workOrders as $workOrder) {
            $review = $workOrder->reviews()->latest('reviewed_at')->first();

            if (! $review->revision_deadline?->isPast()
                || $this->sudahDikirim(WorkOrderNotification::REVISION_OVERDUE, 'work_order_id', $workOrder->id, $review->reviewed_at)) {
                continue;
            }

            Notification::send(
                $workOrder->operationalTeam()->with('user')->get()->pluck('user')->merge($this->aktif('maintenance_unit')),
                new WorkOrderNotification($workOrder, WorkOrderNotification::REVISION_OVERDUE,
                    "Target revisi Work Order {$workOrder->reference_number} sudah lewat ({$this->jam($review->revision_deadline)}), belum diajukan selesai lagi."),
            );
            $jumlah++;
        }

        return $jumlah;
    }

    /**
     * WO yang belum selesai melewati `target_date` — kartu "Lewat Target" dashboard Unit,
     * kriteria yang sama (MaintenanceDashboardService::overdue). Ke Unit saja: dia yang
     * menetapkan targetnya dan yang memutuskan langkah berikutnya.
     */
    private function lewatTarget(): int
    {
        $unit = $this->aktif('maintenance_unit');
        $jumlah = 0;

        $workOrders = WorkOrder::whereIn('repair_status', WorkOrder::OPEN_STATUSES)
            ->whereNotNull('target_date')
            ->where('target_date', '<', now())
            ->get();

        foreach ($workOrders as $workOrder) {
            // ponytail: sekali per WO. Kalau target_date nanti bisa diundur, tambahkan
            // $sejak = waktu target diubah supaya target baru berbunyi lagi.
            if ($this->sudahDikirim(WorkOrderNotification::TARGET_OVERDUE, 'work_order_id', $workOrder->id)) {
                continue;
            }

            Notification::send($unit, new WorkOrderNotification($workOrder, WorkOrderNotification::TARGET_OVERDUE,
                "Work Order {$workOrder->reference_number} lewat target ({$this->jam($workOrder->target_date)}) dan belum selesai."));
            $jumlah++;
        }

        return $jumlah;
    }

    /**
     * `data` bertipe text (tabel bawaan Laravel), jadi di-cast ke jsonb untuk dibaca.
     * $kunci selalu literal dari kelas ini, bukan masukan pengguna.
     */
    private function sudahDikirim(string $event, string $kunci, string|int $id, ?Carbon $sejak = null): bool
    {
        return DB::table('notifications')
            ->whereRaw("data::jsonb->>'event' = ?", [$event])
            ->whereRaw("data::jsonb->>'{$kunci}' = ?", [(string) $id])
            ->when($sejak, fn ($q) => $q->where('created_at', '>=', $sejak))
            ->exists();
    }

    private function aktif(string $role)
    {
        return User::where('role', $role)->where('is_active', true)->get();
    }

    private function jam(Carbon $waktu): string
    {
        return $waktu->format('d/m/Y H:i');
    }
}
