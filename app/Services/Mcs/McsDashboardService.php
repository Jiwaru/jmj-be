<?php

namespace App\Services\Mcs;

use App\Models\IncidentReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lima angka di layar Dashboard MCS Supervisor.
 *
 * Dua yang pertama HANYA berbeda karena pengesahannya dipisah per BA. Kalau suatu
 * saat keduanya selalu sama, itu tandanya pengesahan BA Kejadian dan BA Kerusakan
 * diam-diam kembali digabung jadi satu aksi — ada testnya.
 */
class McsDashboardService
{
    public function summary(): array
    {
        return [
            'incident_ba_queue' => IncidentReport::awaitingIncidentBa()->count(),
            'damage_ba_queue' => IncidentReport::awaitingDamageBa()->count(),
            'damage_ba_queue_estimation' => $this->damageBaQueueEstimation(),
            'pending_relief_letter' => IncidentReport::awaitingReliefLetter()->count(),
            'estimation_this_month' => $this->estimationThisMonth(),
        ];
    }

    /**
     * Nominal antrean BA Kerusakan — berkas yang SAMA dengan damage_ba_queue, jadi
     * "4 Berkas · Rp …" tidak bisa melenceng satu sama lain. Definisi kedua antrean
     * ada di scope IncidentReport, dipakai juga daftar `?queue=` di incident-reports.
     *
     * Dari `total_estimation` laporan, bukan `damage_ba.total_amount`: di antrean ini
     * barisnya belum ada atau ditolak, dan nilai yang hidup ada di laporan — koreksi
     * volume MCS langsung tercermin. Tidak bisa dobel: damage_ba.damage_report_id UNIQUE.
     */
    private function damageBaQueueEstimation(): string
    {
        return number_format((float) IncidentReport::awaitingDamageBa()->sum('total_estimation'), 2, '.', '');
    }

    /**
     * "Total Estimasi Ganti Rugi" bulan berjalan — yang nilainya sudah DITETAPKAN,
     * artinya BA Kerusakannya sudah disahkan. Yang masih mengantre belum tentu jadi.
     */
    private function estimationThisMonth(): string
    {
        $total = DB::table('damage_ba')
            ->where('validation_status', 'validated')
            ->whereBetween('validated_at', [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ])
            ->sum('total_amount');

        return number_format((float) $total, 2, '.', '');
    }
}
