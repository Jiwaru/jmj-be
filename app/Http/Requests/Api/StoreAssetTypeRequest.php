<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetTypeRequest extends FormRequest
{
    // sinkron dengan CHECK constraint asset_types_category_check di migration.
    public const CATEGORIES = [
        'road_body', 'road_equipment', 'complementary_structure', 'support_facility', 'connecting_road',
    ];

    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(self::CATEGORIES)],
            // Unique per kategori, bukan global — replikasi unique(['category','name']) di migration
            // supaya gagalnya 422 rapi, bukan 500 mentah dari constraint DB.
            'name' => ['required', 'string', 'max:150', Rule::unique('asset_types')->where('category', $this->input('category'))],
            'standard_photo_id' => ['nullable', 'integer', Rule::exists('documents', 'id')],
            'description' => ['nullable', 'string'],
        ];
    }
}
