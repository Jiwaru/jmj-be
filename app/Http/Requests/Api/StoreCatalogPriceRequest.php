<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCatalogPriceRequest extends FormRequest
{
    // sinkron dengan CHECK constraint catalog_prices_change_reason_check di migration.
    public const CHANGE_REASONS = ['inflation', 'vendor_adjustment', 'admin_revision'];

    public function rules(): array
    {
        return [
            // Tiga-tiganya nullable persis skema (lihat komentar di migration catalog_prices):
            // perhitungan ganti rugi membaca max_price, jadi price kosong tetap sah.
            'price' => ['nullable', 'numeric', 'min:0'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'change_reason' => ['required', Rule::in(self::CHANGE_REASONS)],
            // changed_by TIDAK diterima dari klien — diisi dari user login di controller,
            // sama alasannya dengan damage_ba_id di StoreWorkOrderRequest.
        ];
    }
}
