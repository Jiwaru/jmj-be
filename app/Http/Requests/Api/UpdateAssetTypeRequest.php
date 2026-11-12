<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetTypeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(StoreAssetTypeRequest::CATEGORIES)],
            'name' => [
                'required', 'string', 'max:150',
                Rule::unique('asset_types')->where('category', $this->input('category'))->ignore($this->route('assetType')),
            ],
            'standard_photo_id' => ['nullable', 'integer', Rule::exists('documents', 'id')],
            'description' => ['nullable', 'string'],
        ];
    }
}
