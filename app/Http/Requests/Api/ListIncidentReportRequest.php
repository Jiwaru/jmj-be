<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ListIncidentReportRequest extends FormRequest
{
    /**
     * `sync_status` sengaja TIDAK bisa difilter. Di server nilainya selalu 'sent';
     * 'draft' dan 'queued' cuma ada di database lokal HP. Menerimanya di sini
     * membuat filter yang tidak pernah menyaring apa pun — lebih buruk daripada
     * tidak ada, karena yang memakainya mengira daftarnya memang kosong.
     */
    public function rules(): array
    {
        return [
            'report_status' => ['nullable', 'in:pending_verification,verified,rejected,closed'],
            // Tab "Menunggu BAK"/"Menunggu BAR" dan halaman Pantauan Masa Tenggang MCS —
            // definisinya scope IncidentReport, sama dengan badge di mcs/summary. Kalau
            // ada, report_status diabaikan. `active` = tab "Semua" Verifikasi Laporan:
            // semua kecuali closed, karena closed tempatnya di Arsip.
            'queue' => ['nullable', 'in:awaiting_bak,awaiting_bar,awaiting_relief_letter,active'],
            // Filter bulan halaman Arsip (report_status=closed). Bulan KEJADIAN, bukan
            // bulan ditutup — keputusan Rafiq 6 Okt. Bisa digabung dengan queue maupun
            // report_status.
            'month' => ['nullable', 'date_format:Y-m'],
        ];
    }
}
