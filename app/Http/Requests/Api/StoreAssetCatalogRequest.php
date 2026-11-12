<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetCatalogRequest extends FormRequest
{
    public function rules(): array
    {
        return static::sharedRules();
    }

    // Dipakai ulang oleh UpdateAssetCatalogRequest — PUT di sini berarti kirim ulang
    // seluruh field, bukan patch sebagian, jadi aturannya sama persis.
    public static function sharedRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'asset_type_id' => ['required', 'integer', Rule::exists('asset_types', 'id')],
            'component' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'unit_of_measurement' => ['required', 'string', 'max:15'],
            'standard_photo_id' => ['nullable', 'integer', Rule::exists('documents', 'id')],
            'specification' => ['nullable', 'array'],
            'designlife_years' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'primary_vendor' => ['nullable', 'string', 'max:150'],
        ];
    }
}
