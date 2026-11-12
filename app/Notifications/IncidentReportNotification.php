<?php

namespace App\Notifications;

use App\Models\IncidentReport;
use Illuminate\Notifications\Notification;

/**
 * Isi lonceng untuk kejadian laporan insiden — Petugas Lapangan (BA disahkan/ditolak,
 * laporan ditutup) dan MCS Supervisor (laporan yang ditolak dikirim ulang, tenggat
 * surat keringanan).
 *
 * Kembaran WorkOrderNotification: `event` + `message` sama, cuma kunci subjeknya
 * `incident_report_id` (uuid) alih-alih `work_order_id`. Channel `database` saja, ditulis
 * di transaksi yang sama dengan perubahan laporannya.
 */
class IncidentReportNotification extends Notification
{
    public const INCIDENT_BA_VALIDATED = 'incident_ba_validated';

    public const INCIDENT_BA_REJECTED = 'incident_ba_rejected';

    public const DAMAGE_BA_VALIDATED = 'damage_ba_validated';

    public const DAMAGE_BA_REJECTED = 'damage_ba_rejected';

    /** Untuk MCS: laporan yang ditolak sudah dibetulkan dan masuk antrean lagi. */
    public const RESUBMITTED = 'resubmitted';

    /** Semua kerusakannya selesai diperbaiki — laporan `closed`. */
    public const CLOSED = 'closed';

    /** Scheduler, untuk MCS: tenggat surat keringanan tinggal ≤ 24 jam. */
    public const RELIEF_DEADLINE_NEAR = 'relief_deadline_near';

    /** Scheduler, untuk MCS: tenggat surat keringanan lewat, suratnya belum masuk. */
    public const RELIEF_DEADLINE_PASSED = 'relief_deadline_passed';

    public function __construct(
        private readonly IncidentReport $report,
        private readonly string $event,
        private readonly string $message,
    ) {}

    /**
     * Nama laporan di kalimat notifikasi. report_number baru terbit saat BA Kejadian
     * disahkan, jadi laporan yang ditolak di putaran pertama disebut dari lokasinya.
     */
    public static function label(IncidentReport $report): string
    {
        return $report->report_number
            ? "laporan {$report->report_number}"
            : "laporan KM {$report->km}+{$report->m} arah {$report->route}";
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'incident_report_id' => $this->report->uuid,
            'report_number' => $this->report->report_number,
            'message' => $this->message,
        ];
    }
}
