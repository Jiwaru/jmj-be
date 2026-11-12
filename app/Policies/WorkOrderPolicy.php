<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;

/**
 * Kepemilikan Work Order, untuk Fase 5.
 *
 * Sampai Fase 4 dan 6, Work Order memang tidak dimiliki siapa pun — yang membatasinya
 * `role:maintenance_unit` lalu `repair_status` yang dijaga Service, dan karena itu
 * WorkOrderController sengaja tidak punya Policy. Fase 5 mengubah keadaannya: petugas
 * maintenance punya WO **miliknya sendiri**, dan role saja tidak bisa membedakan WO
 * miliknya dari WO milik regu sebelah.
 *
 * Aturannya sudah tertulis di KONVENSI-BACKEND §"Pengecekan role" jauh sebelum
 * endpoint-nya ada: *"maintenance_officer cuma boleh menyentuh WO tempat dia terdaftar
 * di operational_team"*. Ini pelaksanaannya.
 *
 * ALLOWLIST, bukan denylist. Role yang tidak disebut tidak dapat apa pun — termasuk
 * role kelima yang mungkin ditambahkan nanti.
 */
class WorkOrderPolicy
{
    /**
     * Membuka detail sebuah Work Order.
     *
     * Unit Maintenance melihat semuanya: dia yang menerbitkan dan yang mengevaluasi.
     * Petugas maintenance cuma yang ditugaskan kepadanya.
     */
    public function view(User $user, WorkOrder $workOrder): bool
    {
        return match ($user->role) {
            'maintenance_unit' => true,
            'maintenance_officer' => $this->ditugaskan($user, $workOrder),
            default => false,
        };
    }

    /**
     * Menulis baris log Fase 5 — terima tugas, verifikasi APD, catat progres, oper
     * shift, lapor kendala, ajukan selesai.
     *
     * Unit Maintenance TIDAK dapat, walau dia boleh melihat semuanya. Baris log adalah
     * kesaksian orang yang ada di lokasi; kalau Unit Maintenance boleh menulisnya, bukti
     * yang dia evaluasi sendiri di Fase 6 bisa jadi bukti yang dia tulis sendiri.
     */
    public function log(User $user, WorkOrder $workOrder): bool
    {
        return $user->role === 'maintenance_officer' && $this->ditugaskan($user, $workOrder);
    }

    /**
     * Terdaftar di `operational_team` untuk WO ini.
     *
     * Regu bisa lebih dari satu orang dan pekerjaan bisa dioper antar shift, jadi yang
     * ditanya keanggotaan regu — BUKAN `work_orders.received_by`. Memakai received_by
     * berarti cuma orang yang kebetulan menekan "Terima" yang bisa mencatat apa pun,
     * dan rekan seregunya terkunci di luar pekerjaannya sendiri.
     */
    private function ditugaskan(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->operationalTeam()->where('user_id', $user->id)->exists();
    }
}
