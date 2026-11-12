<?php

namespace App\Http\Controllers\Api\Maintenance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAssetTypeRequest;
use App\Http\Requests\Api\UpdateAssetTypeRequest;
use App\Http\Requests\Api\UploadAssetPhotoRequest;
use App\Models\AssetType;
use App\Services\Shared\DocumentStorageService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Master data tingkat kategori (category -> TYPE -> component). Read di sini untuk layar
 * admin (berhalaman, bisa ditulis) — beda dari GET /sync/bootstrap yang ngedump semua
 * baris datar tanpa halaman untuk bekal luring dan tidak disentuh oleh controller ini.
 */
class AssetTypeController extends Controller
{
    public function __construct(private readonly DocumentStorageService $documents) {}

    public function index(): LengthAwarePaginator
    {
        return AssetType::query()->orderBy('category')->orderBy('name')->paginate(20);
    }

    public function show(AssetType $assetType): array
    {
        return ['asset_type' => $assetType];
    }

    public function store(StoreAssetTypeRequest $request): array
    {
        $assetType = AssetType::create($request->validated());

        return ['asset_type' => $assetType, 'message' => 'Jenis aset dibuat.'];
    }

    public function update(UpdateAssetTypeRequest $request, AssetType $assetType): array
    {
        $assetType->update($request->validated());

        return ['asset_type' => $assetType, 'message' => 'Jenis aset diperbarui.'];
    }

    /**
     * asset_catalog.asset_type_id pakai restrictOnDelete() — dicek dulu di sini supaya
     * kegagalannya 422 yang jelas, bukan 500 mentah dari Postgres.
     */
    public function destroy(AssetType $assetType): array
    {
        if ($assetType->assetCatalogs()->exists()) {
            throw ValidationException::withMessages([
                'asset_type' => 'Jenis aset ini masih dipakai katalog, tidak bisa dihapus.',
            ]);
        }

        $assetType->delete();

        return ['message' => 'Jenis aset dihapus.'];
    }

    /**
     * Foto standar jenis aset — satu slot, unggahan baru menggantikan yang lama.
     * Dilihatnya lewat GET /documents/{id} seperti dokumen lain, dibuka semua role
     * (lihat DocumentPolicy::view()).
     */
    public function uploadPhoto(UploadAssetPhotoRequest $request, AssetType $assetType): array
    {
        $document = $this->documents->storeForAssetType($assetType, $request->file('file'), $request->user());

        return [
            'asset_type' => $assetType->refresh(),
            'document' => $document,
            'message' => 'Foto jenis aset diperbarui.',
        ];
    }
}
