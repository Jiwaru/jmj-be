<?php

namespace App\Notifications;

use App\Models\WorkOrder;
use Illuminate\Notifications\Notification;

/**
 * Isi lonceng untuk semua kejadian WO — Petugas Maintenance (assigned, returned,
 * accepted, coordinator, removed) maupun Unit Maintenance (submitted, obstacle).
 * SATU kelas: yang membedakan `event`, dan bentuk datanya sama, jadi FE cukup satu
 * komponen.
 *
 * Channel `database` saja, TANPA push dan tanpa queue: ditulis di transaksi yang sama
 * dengan perubahan WO-nya, jadi kalau perubahannya batal, notifikasinya ikut batal.
 */
class WorkOrderNotification extends Notification
{
    public const ASSIGNED = 'assigned';

    public const RETURNED = 'returned';

    public const ACCEPTED = 'accepted';

    /** Jadi koordinator baru lewat oper shift. */
    public const COORDINATOR = 'coordinator';

    /** Dikeluarkan dari regu lewat ganti regu — WO-nya hilang dari daftarnya. */
    public const REMOVED = 'removed';

    /** Untuk Unit Maintenance: regu mengajukan selesai, menunggu evaluasi. */
    public const SUBMITTED = 'submitted';

    /** Untuk Unit Maintenance: regu melaporkan kendala. */
    public const OBSTACLE = 'obstacle';

    /** Scheduler: target revisi lewat sebelum diajukan selesai lagi — regu + Unit. */
    public const REVISION_OVERDUE = 'revision_overdue';

    /** Scheduler: WO belum selesai melewati target_date — Unit. */
    public const TARGET_OVERDUE = 'target_overdue';

    public function __construct(
        private readonly WorkOrder $workOrder,
        private readonly string $event,
        private readonly string $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'work_order_id' => $this->workOrder->id,
            'reference_number' => $this->workOrder->reference_number,
            'message' => $this->message,
        ];
    }
}
