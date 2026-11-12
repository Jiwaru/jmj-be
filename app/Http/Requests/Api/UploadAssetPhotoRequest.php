<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Foto standar jenis aset / komponen katalog. Dipakai DUA endpoint
 * (asset-types/{id}/photo dan asset-catalog/{id}/photo) — bukan unggahan Petugas
 * Lapangan luring, jadi tidak ada `file_uuid`: ini satu slot foto yang DIGANTI tiap
 * unggah, bukan baris yang diantrekan.
 */
class UploadAssetPhotoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png', 'max:10240'],
        ];
    }
}
