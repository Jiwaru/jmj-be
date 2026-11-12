<?php

namespace App\Http\Controllers\Api\Maintenance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreCatalogPriceRequest;
use App\Models\AssetCatalog;
use Illuminate\Database\Eloquent\Collection;

/**
 * Histori harga per item katalog. CREATE + READ saja, sengaja tanpa update/destroy:
 * damaged_items.catalog_price_id menunjuk baris tertentu untuk hitung ganti rugi
 * (SK 40/KPTS-JMJ), jadi ganti harga = baris baru, bukan baris lama yang ditimpa.
 */
class CatalogPriceController extends Controller
{
    public function index(AssetCatalog $assetCatalog): Collection
    {
        return $assetCatalog->catalogPrices()->orderByDesc('effective_from')->get();
    }

    public function store(StoreCatalogPriceRequest $request, AssetCatalog $assetCatalog): array
    {
        $price = $assetCatalog->catalogPrices()->create($request->validated() + [
            'changed_by' => $request->user()->id,
        ]);

        return ['catalog_price' => $price, 'message' => 'Harga baru ditambahkan.'];
    }
}
