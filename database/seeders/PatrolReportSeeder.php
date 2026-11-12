<?php

namespace Database\Seeders;

use App\Models\AssetCatalog;
use App\Models\Document;
use App\Models\PatrolReport;
use App\Models\User;
use App\Services\Shared\PatrolReportSyncService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Laporan patroli contoh — supaya `GET /maintenance/patrol-queue` dan kartu "Rujukan
 * Laporan Patroli" di form penerbitan WO tidak selalu kosong.
 *
 * HANYA MENAMBAH, aman diulang dan aman di staging: uuid-nya tetap, dan laporan yang
 * sudah ada dilewati — termasuk yang sudah dijadikan WO (service menolak mengubahnya).
 * Dipanggil DatabaseSeeder dan StagingTopUpSeeder.
 *
 * Dibuat lewat PatrolReportSyncService, jalur yang sama dengan HP petugas.
 */
class PatrolReportSeeder extends Seeder
{
    public function run(): void
    {
        $petugas = User::where('identity_code', 'JMTO001')->firstOrFail();
        $sync = app(PatrolReportSyncService::class);

        $aset = fn (string $component) => (AssetCatalog::where('component', $component)->first()
            ?? AssetCatalog::orderBy('id')->firstOrFail())->id;

        $laporan = [
            ['9b1f0c2e-0001-4a00-8000-000000000001', 'Guard Rail Beam (4000X312X2.7)', 'Jalur Utama', 14, 200, 'A', 'Bahu Jalan',
                'W-Beam tergores dan satu tiang miring akibat benturan, panjang ± 8 m.', -7.6012345, 110.6523456],
            ['9b1f0c2e-0001-4a00-8000-000000000002', 'Concrete Barrier, Tipe B (1 Muka)', 'Jalur Utama', 21, 500, 'B', 'Lajur 1',
                'Concrete barrier median retak dan gompal di dua segmen.', -7.6312345, 110.6123456],
            ['9b1f0c2e-0001-4a00-8000-000000000003', 'Guard Rail Beam (4000X312X2.7)', 'Gerbang Tol', 35, 0, 'A', 'Lajur 2',
                'Ujung guardrail di pulau gerbang bengkok ke arah lajur.', null, null],
            ['9b1f0c2e-0001-4a00-8000-000000000004', 'Concrete Barrier, Tipe B (1 Muka)', 'Rest Area', 35, 500, 'B', null,
                'Barrier pembatas area parkir bergeser ± 30 cm.', -7.6812345, 110.5523456],
        ];

        foreach ($laporan as $i => [$uuid, $komponen, $lokasi, $km, $m, $route, $lane, $uraian, $lat, $lng]) {
            if (PatrolReport::whereKey($uuid)->exists()) {
                continue;
            }

            $sync->upsert($uuid, $petugas, [
                'patrolled_at' => now()->subDays(4 - $i)->setTime(9 + $i, 15)->toDateTimeString(),
                'route' => $route,
                'is_damaged' => true,
                'asset_catalog_id' => $aset($komponen),
                'location_type' => $lokasi,
                'km' => $km,
                'm' => $m,
                'lane' => $lane,
                'damage_description' => $uraian,
                'latitude' => $lat,
                'longitude' => $lng,
            ]);

            $this->foto($uuid, $petugas, "Patroli KM {$km}+".str_pad((string) $m, 3, '0', STR_PAD_LEFT)." {$route}");
        }

        // Satu patroli TANPA temuan — tidak boleh muncul di antrean, dan itulah yang
        // membuktikan saringan `is_damaged` di antrean bekerja.
        $aman = '9b1f0c2e-0001-4a00-8000-000000000005';
        if (! PatrolReport::whereKey($aman)->exists()) {
            $sync->upsert($aman, $petugas, [
                'patrolled_at' => now()->subHours(3)->toDateTimeString(),
                'route' => 'A',
                'is_damaged' => false,
            ]);
        }
    }

    /** Pola path sama dengan DocumentStorageService::storeForPatrolReport(). */
    private function foto(string $uuid, User $uploader, string $label): void
    {
        $path = "patroli/{$uuid}/".Str::uuid().'.png';
        Storage::disk('local')->put($path, PlaceholderImage::png($label));

        Document::create([
            'documentable_type' => 'patrol_report',
            'documentable_id' => $uuid,
            'document_type' => 'patrol_photo',
            'path' => $path,
            'mime_type' => 'image/png',
            'size_bytes' => Storage::disk('local')->size($path),
            'uploaded_by' => $uploader->id,
            'uploaded_at' => now(),
        ]);
    }
}
