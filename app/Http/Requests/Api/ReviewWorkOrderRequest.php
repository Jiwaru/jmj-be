<?php

namespace App\Http\Requests\Api;

use App\Models\WorkOrderReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fase 6 — Unit Maintenance memeriksa bukti yang diajukan regu.
 *
 * Dua boolean, bukan satu kesimpulan: `meets_quality_standard` menilai MUTU
 * pekerjaannya, `quantity_matches_log` mencocokkan JUMLAH terpasang di lapangan dengan
 * `damaged_items.quantity` yang ditagihkan ke pengguna jalan. Yang kedua itu satu-
 * satunya pemeriksaan yang mencegah selisih antara yang ditagih dan yang benar-benar
 * dikerjakan, dan menggabungkannya jadi satu "lulus/tidak" menghapus bedanya.
 *
 * Keduanya tetap DICATAT apa adanya walau `outcome`-nya diterima — evaluator boleh
 * menerima pekerjaan yang mutunya pas-pasan, dan itu justru yang perlu terbaca
 * belakangan.
 */
class ReviewWorkOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'meets_quality_standard' => ['required', 'boolean'],
            'quantity_matches_log' => ['required', 'boolean'],
            'outcome' => ['required', Rule::in(config('enums.wo_review_outcome'))],

            // Wajib kecuali diterima. Mengembalikan pekerjaan tanpa menyebut apa yang
            // kurang berarti regu datang lagi ke lokasi dan menebak — dan putaran
            // evaluasi kedua gagal karena alasan yang sama.
            'rejection_notes' => [
                Rule::requiredIf(fn () => $this->input('outcome') !== WorkOrderReview::OUTCOME_DITERIMA),
                'nullable', 'string', 'min:5', 'max:1000',
            ],

            // "Target Revisi" — cuma untuk pekerjaan yang dikembalikan. Opsional supaya
            // web Unit versi lama tidak patah; desain menampilkannya kalau ada.
            'revision_deadline' => [
                Rule::prohibitedIf(fn () => $this->input('outcome') === WorkOrderReview::OUTCOME_DITERIMA),
                'nullable', 'date', 'after:now',
            ],

            // HANYA untuk WO manual, dan Service yang menegakkannya (dia yang tahu WO
            // ini punya damaged_items atau tidak). WO dari insiden mengambil angkanya
            // dari SUM(damaged_items.subtotal) — nilai yang sudah disepakati bersama
            // pengguna jalan dan tercetak di BA Kerusakan, jadi mengetiknya ulang di
            // sini cuma membuka jalan supaya biaya perbaikan berbeda dari yang ditagih.
            'cost_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'rejection_notes.required' => 'Sebutkan apa yang harus diperbaiki sebelum mengembalikan pekerjaan ke regu.',
            'revision_deadline.prohibited' => 'Target revisi cuma untuk pekerjaan yang dikembalikan ke regu.',
            'revision_deadline.after' => 'Target revisi harus di masa depan.',
        ];
    }
}
