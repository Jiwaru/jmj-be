<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Foto bukti kerusakan yang menempel ke satu laporan patroli.
 *
 * TIDAK ADA `document_type`, pola sama dengan UploadWorkOrderPhotoRequest: cuma satu
 * jenis foto di jalur ini (`patrol_photo`), jadi tidak ada yang perlu dipilih klien.
 */
class UploadPatrolDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file_uuid' => ['required', 'uuid'],
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png', 'max:10240'],
        ];
    }
}
