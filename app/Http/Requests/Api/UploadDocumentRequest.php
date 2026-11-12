<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentRequest extends FormRequest
{
    /**
     * Jenis dokumen yang boleh diunggah Petugas Lapangan. 'standard_photo' (dikelola
     * dari web) dan 'wo_photo' (punya Petugas Maintenance, lewat
     * UploadWorkOrderPhotoRequest) sengaja tidak ada di sini.
     */
    public const ALLOWED_TYPES = [
        'scene_photo', 'vehicle_photo', 'id_card_photo', 'driver_license_photo',
        'vehicle_registration_photo', 'statement_photo', 'customer_signature', 'relief_letter',
    ];

    public function rules(): array
    {
        return [
            // Kunci idempotensi: HP yang bikin, dan path file diturunkan darinya.
            // WAJIB divalidasi sebagai uuid betulan — kalau diterima apa adanya,
            // isi '../../../secret' menulis file ke luar folder. "Kan dari aplikasi
            // kita sendiri" tidak berlaku: aplikasi kita sendiri itu APK di HP orang.
            'file_uuid' => ['required', 'uuid'],
            'document_type' => ['required', 'in:'.implode(',', self::ALLOWED_TYPES)],
            // Posisi pihak di array `culprits[]` yang sama, bukan culprit.id — id itu
            // belum tentu ada waktu unggah (foto boleh duluan sebelum sync) dan berubah
            // tiap resubmit. Tanpa batas atas di sini: laporan yang menyertainya belum
            // tentu sudah tersinkron, jadi jumlah culprits sebenarnya belum diketahui.
            'culprit_index' => ['nullable', 'integer', 'min:0'],
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png,pdf', 'max:10240'],
        ];
    }
}
