<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Koreksi MCS sebelum pengesahan — salah ketik, bukan penulisan ulang laporan.
 *
 * DAFTAR INI SENGAJA PENDEK, dan yang TIDAK ada di sini lebih penting daripada yang
 * ada. Field di luar daftar tidak divalidasi, jadi tidak ikut ke service dan tidak
 * pernah tertulis — mengirimnya tidak menghasilkan 422, cuma diabaikan.
 *
 * Yang tidak boleh dikoreksi MCS:
 *   analyze                mengubahnya membalik is_asset_broken, artinya menentukan
 *                          perlu tidaknya BA Kerusakan terbit sama sekali
 *   causer, conclusion,    penilaian petugas di TKP. MCS tidak ada di sana; kalau
 *   weather_condition,     isinya salah, tolak BA-nya dan biarkan petugas yang
 *   incident_explanation   membetulkan
 *   menambah/membuang      itu menulis ulang laporan, bukan mengoreksinya
 *   baris damaged_items
 *
 * Lokasi justru boleh karena pasangan (route, km) satu-satunya jangkar analisis
 * titik rawan — salah ketik di situ merusak analisis dan tidak bisa diperbaiki mundur.
 */
class CorrectIncidentReportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // km dan route tidak boleh dikosongkan lewat koreksi — kiriman petugas
            // sendiri sudah mewajibkannya, dan mengizinkannya di sini membuka pintu
            // belakang ke keadaan yang tidak bisa dicapai lewat pintu depan.
            'km' => ['sometimes', 'integer', 'min:0'],
            'm' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999'],
            'route' => ['sometimes', Rule::in(config('enums.route'))],
            'lane' => ['sometimes', 'nullable', Rule::in(config('enums.lane'))],
            'location_type' => ['sometimes', Rule::in(config('enums.location_type'))],

            // Baris yang sudah ada saja. `id` wajib: tanpa itu tidak ada cara
            // menunjuk baris mana yang dikoreksi, dan urutan array bukan identitas.
            'items' => ['sometimes', 'array'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['sometimes', 'numeric', 'gt:0'],
            'items.*.damage_percentage' => ['sometimes', 'numeric', 'between:0,100'],
        ];
    }
}
