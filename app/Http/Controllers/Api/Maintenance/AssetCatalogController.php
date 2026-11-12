<?php

namespace App\Http\Controllers\Api\Maintenance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAssetCatalogRequest;
use App\Http\Requests\Api\UpdateAssetCatalogRequest;
use App\Http\Requests\Api\UploadAssetPhotoRequest;
use App\Models\AssetCatalog;
use App\Services\Shared\DocumentStorageService;
use App\Support\Search;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AssetCatalogController extends Controller
{
    public function __construct(private readonly DocumentStorageService $documents) {}

    /**
     * Pencarian DI SERVER, bukan di halaman yang sudah termuat: aset di halaman 3 tidak
     * boleh dijawab "tidak ditemukan" cuma karena halamannya belum di-scroll.
     */
    public function index(Request $request): LengthAwarePaginator
    {
        return AssetCatalog::query()
            // standard_photo_id IKUT di-select: FE butuh foto jenis asetnya juga di
            // layar list, bukan cuma di detail.
            ->with('assetType:id,category,name,standard_photo_id')
            ->when($request->integer('asset_type_id'), fn ($q, $v) => $q->where('asset_type_id', $v))
            ->when(Search::pattern($request->input('search')), fn ($q, $pola) => $q->where(
                fn ($q) => $q->where('name', 'ilike', $pola)->orWhere('component', 'ilike', $pola)
            ))
            ->orderBy('asset_type_id')->orderBy('component')
            ->paginate(Search::perPage($request));
    }

    public function show(AssetCatalog $assetCatalog): array
    {
        return ['asset_catalog' => $assetCatalog->load('assetType')];
    }

    public function store(StoreAssetCatalogRequest $request): array
    {
        $assetCatalog = AssetCatalog::create($request->validated());

        return ['asset_catalog' => $assetCatalog, 'message' => 'Katalog aset dibuat.'];
    }

    public function update(UpdateAssetCatalogRequest $request, AssetCatalog $assetCatalog): array
    {
        $assetCatalog->update($request->validated());

        return ['asset_catalog' => $assetCatalog, 'message' => 'Katalog aset diperbarui.'];
    }

    /**
     * damaged_items, work_orders, DAN catalog_prices tiga-tiganya restrictOnDelete ke
     * sini — item yang sudah pernah dipakai di laporan/pekerjaan atau punya riwayat harga
     * tidak boleh hilang.
     */
    public function destroy(AssetCatalog $assetCatalog): array
    {
        if (
            $assetCatalog->damagedItems()->exists()
            || $assetCatalog->workOrders()->exists()
            || $assetCatalog->catalogPrices()->exists()
        ) {
            throw ValidationException::withMessages([
                'asset_catalog' => 'Item katalog ini sudah dipakai di laporan/pekerjaan, tidak bisa dihapus.',
            ]);
        }

        $assetCatalog->delete();

        return ['message' => 'Katalog aset dihapus.'];
    }

    /**
     * Foto standar komponen katalog — pasangan AssetTypeController::uploadPhoto(),
     * slot sendiri (`asset_catalog.standard_photo_id`), terpisah dari foto jenis
     * asetnya. Unggahan baru menggantikan yang lama.
     */
    public function uploadPhoto(UploadAssetPhotoRequest $request, AssetCatalog $assetCatalog): array
    {
        $document = $this->documents->storeForAssetCatalog($assetCatalog, $request->file('file'), $request->user());

        return [
            'asset_catalog' => $assetCatalog->refresh(),
            'document' => $document,
            'message' => 'Foto komponen katalog diperbarui.',
        ];
    }
}
