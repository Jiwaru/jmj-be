<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Surat permohonan keringanan yang diunggah MCS Supervisor — pengguna jalan membawanya
 * ke kantor, sementara petugas pelapornya bisa sudah ganti shift.
 *
 * TIDAK ADA `document_type`, pola sama dengan UploadPatrolDocumentRequest: jenisnya
 * dipaku server ke `relief_letter`. Kalau dibiarkan dikirim klien, route ini jadi pintu
 * MCS mengganti foto KTP atau TKP yang sudah jadi lampiran BA yang diteken.
 *
 * PDF ikut diterima — surat bermaterai lebih sering dipindai daripada difoto.
 */
class UploadReliefLetterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file_uuid' => ['required', 'uuid'],
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png,pdf', 'max:10240'],
        ];
    }
}
