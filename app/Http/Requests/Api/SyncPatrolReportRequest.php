<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload sinkronisasi laporan patroli. Mirror SyncIncidentReportRequest, jauh lebih
 * kecil — satu baris, tidak ada baris anak.
 *
 * `is_damaged` menentukan requiredness kolom kerusakan, dua arah — pola sama dengan
 * `causer_other` di atas `causer`: WAJIB kalau is_damaged=true, DILARANG kalau tidak.
 * Tidak ada harga/persentase kerusakan: temuan patroli tidak menagih siapa pun.
 */
class SyncPatrolReportRequest extends FormRequest
{
    public function rules(): array
    {
        // `,true` — BUKAN `,1`. Laravel cuma mengonversi string literal 'true'/'false'
        // jadi boolean saat membandingkan dengan field lain yang punya rule 'boolean'
        // (ValidatesAttributes::convertValuesToBoolean). '1' tidak dikonversi, jadi
        // dibandingkan strict dengan is_damaged yang bool `true` — selalu gagal, dan
        // damage fields selalu dianggap dilarang walau is_damaged benar-benar true.
        $wajib = 'required_if:is_damaged,true';
        $dilarang = 'prohibited_unless:is_damaged,true';

        return [
            'patrolled_at' => ['required', 'date', 'before_or_equal:now'],
            'route' => ['required', Rule::in(config('enums.route'))],
            'is_damaged' => ['required', 'boolean'],
            // GPS HP saat melapor — sumber titik peta "Lokasi Presisi" di Work Order.
            // Opsional: GPS bisa mati atau ditolak izinnya, dan KM tetap jangkar utama.
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],

            'asset_catalog_id' => [$wajib, $dilarang, 'integer', Rule::exists('asset_catalog', 'id')],
            'location_type' => [$wajib, $dilarang, Rule::in(config('enums.location_type'))],
            'km' => [$wajib, $dilarang, 'integer', 'min:0', 'max:32767'],
            'm' => [$dilarang, 'nullable', 'integer', 'min:0', 'max:999'],
            'lane' => [$dilarang, 'nullable', Rule::in(config('enums.lane'))],
            'damage_description' => [$wajib, $dilarang, 'string', 'max:2000'],
        ];
    }
}
