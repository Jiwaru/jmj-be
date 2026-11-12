<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Foto bukti yang menempel ke satu baris log Work Order.
 *
 * TIDAK ADA `document_type` di sini, dan itu disengaja. Foto yang menempel ke baris log
 * cuma punya satu jenis — `wo_photo` — dan apa artinya sudah diceritakan baris induknya
 * lewat `entry_type` + `bda_stage`. Membiarkan klien mengirim jenisnya berarti membuka
 * kemungkinan dokumen bertanda 'before' menggantung di baris 'shift_handover': sah
 * menurut CHECK, salah menurut kenyataan, dan tidak ada yang bisa menengahi.
 */
class UploadWorkOrderPhotoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Kunci idempotensi, dan path berkas diturunkan darinya. WAJIB uuid betulan:
            // diterima apa adanya, isi '../../../secret' menulis berkas ke luar folder.
            // "Kan dari aplikasi kita sendiri" tidak berlaku — aplikasi kita sendiri itu
            // APK di HP orang.
            'file_uuid' => ['required', 'uuid'],

            // mimes: memeriksa ISI berkas, bukan header multipart kiriman HP. Tanpa itu
            // PNG yang dideklarasikan 'text/html' akan dirender browser sebagai HTML
            // waktu Unit Maintenance membukanya di layar evaluasi.
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png', 'max:10240'],
        ];
    }
}
