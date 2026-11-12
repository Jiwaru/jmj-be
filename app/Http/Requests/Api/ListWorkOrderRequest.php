<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filter daftar Work Order.
 *
 * `repair_status` ditulis apa adanya, tidak dibaca dari config/enums.php: itu STATUS
 * (kosakata yang sama dengan report_status dan validation_status), bukan daftar pilihan
 * yang muncul di form. Yang menjaganya tetap sama dengan database adalah CHECK di
 * migration, dan daftar ini akan langsung terlihat salah begitu ada status yang tidak
 * pernah mengembalikan apa pun.
 */
class ListWorkOrderRequest extends FormRequest
{
    /**
     * `repair_status` boleh satu nilai (`?repair_status=new`) ATAU banyak
     * (`?repair_status[]=preparing&repair_status[]=in_progress`) — tab "Dalam
     * Pengerjaan" FE mencakup dua status sekaligus. Satu nilai dibungkus jadi array di
     * sini supaya kiriman lama tetap jalan dan controller cukup satu whereIn.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('repair_status'))) {
            $this->merge(['repair_status' => [$this->input('repair_status')]]);
        }
    }

    public function rules(): array
    {
        return [
            'repair_status' => ['nullable', 'array'],
            'repair_status.*' => ['in:new,preparing,in_progress,submitted,rework,completed'],
            'priority' => ['nullable', Rule::in(config('enums.wo_priority'))],
            'trigger_source' => ['nullable', Rule::in(config('enums.wo_trigger_source'))],
            'route' => ['nullable', Rule::in(config('enums.route'))],
            // Filter bulan halaman Arsip (repair_status=completed). Bulan TERBIT
            // (created_at), bukan bulan ditutup — keputusan Rafiq 6 Okt.
            'month' => ['nullable', 'date_format:Y-m'],
        ];
    }
}
