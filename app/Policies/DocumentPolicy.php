<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\IncidentReport;
use App\Models\PatrolReport;
use App\Models\User;
use App\Models\WorkOrderLog;

/**
 * Berkas di sini termasuk foto KTP, SIM, dan STNK pihak ketiga. Disk-nya sengaja
 * `local` dan bukan `public` supaya tidak ada URL statis yang bisa ditebak — dan
 * konsekuensinya penjaga hak akses satu-satunya ya kelas ini.
 *
 * Dua cabang sekarang: petugas melihat dokumen laporannya sendiri, MCS Supervisor
 * melihat dokumen laporan mana pun — Bukti Lapangan itu justru yang diperiksanya
 * sebelum mengesahkan BA, dan antreannya berisi laporan orang lain.
 *
 * MENGHAPUS tetap milik petugas saja: yang salah unggah dia, dan dia yang tahu
 * berkas mana yang keliru.
 *
 * Cabang ketiga masuk 3 Sep bersama Fase 5: foto bukti Work Order (`wo_photo`) yang
 * menempel ke baris `work_order_logs`. Dua role yang boleh melihatnya — petugas yang
 * ditugaskan (dia yang memotretnya) dan Unit Maintenance (dia yang mengevaluasinya).
 * MCS Supervisor TIDAK ikut: dia berhenti di pengesahan Berita Acara, dan foto
 * perbaikan bukan lampiran BA mana pun.
 *
 * Foto bukti tidak bisa DIHAPUS siapa pun. Bedanya dengan salah unggah Fase 1: di sana
 * berkasnya belum jadi dasar keputusan apa pun, di sini dia satu-satunya dasar Unit
 * Maintenance menerima atau menolak pekerjaan — dan biaya perbaikan dibukukan dari
 * keputusan itu. Salah foto diperbaiki dengan MENAMBAH foto yang benar, bukan dengan
 * membuang yang lama.
 */
class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        // Foto standar jenis aset/komponen katalog — bukan data pribadi, cuma foto
        // referensi. Semua role boleh lihat: field_officer butuhnya saat memilih aset
        // di form insiden/patroli, maintenance_unit butuhnya saat mengelola katalog.
        if (in_array($document->documentable_type, ['asset_types', 'asset_catalog'], true)) {
            return true;
        }

        if ($document->documentable_type === 'work_order_logs') {
            return $this->bolehMelihatBuktiWorkOrder($user, $document);
        }

        // maintenance_unit yang menindaklanjuti patrol-queue jadi Work Order — sejajar
        // dengan mcs_supervisor yang melihat semua bukti insiden sebelum mengesahkan BA.
        if ($document->documentable_type === 'patrol_report') {
            return $user->role === 'maintenance_unit' || $this->ownsThePatrol($user, $document);
        }

        if ($user->role === 'mcs_supervisor') {
            return $document->documentable_type === 'incident_report';
        }

        return $this->ownsTheIncident($user, $document);
    }

    /**
     * Unit Maintenance melihat semua bukti; petugas cuma bukti pekerjaan yang
     * ditugaskan kepadanya. Dipinjam dari WorkOrderPolicy supaya batas "WO siapa ini"
     * cuma ditulis satu kali.
     */
    private function bolehMelihatBuktiWorkOrder(User $user, Document $document): bool
    {
        $log = WorkOrderLog::with('workOrder')->find($document->documentable_id);

        return $log !== null && (new WorkOrderPolicy)->view($user, $log->workOrder);
    }

    public function delete(User $user, Document $document): bool
    {
        if ($document->documentable_type === 'patrol_report') {
            return $this->ownsThePatrol($user, $document);
        }

        return $this->ownsTheIncident($user, $document);
    }

    private function ownsTheIncident(User $user, Document $document): bool
    {
        // Dokumen yang nempel ke asset_catalog atau work_orders tidak lewat sini.
        if ($document->documentable_type !== 'incident_report') {
            return false;
        }

        return IncidentReport::where('uuid', $document->documentable_id)
            ->where('user_id', $user->id)
            ->exists();
    }

    private function ownsThePatrol(User $user, Document $document): bool
    {
        return PatrolReport::where('uuid', $document->documentable_id)
            ->where('officer_id', $user->id)
            ->exists();
    }
}
