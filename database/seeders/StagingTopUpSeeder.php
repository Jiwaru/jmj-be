<?php

namespace Database\Seeders;

use App\Models\AssetCatalog;
use App\Models\Document;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\MaintenanceOfficer\WorkOrderLogService;
use App\Services\Shared\WorkOrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Data contoh yang MENAMBAH saja — aman dijalankan di server yang datanya tidak boleh
 * di-fresh, dan aman dijalankan berulang:
 *
 *   php artisan db:seed --class=StagingTopUpSeeder --force
 *
 * Dua tugas:
 *   1. Berkas yang barisnya ada di `documents` tapi filenya hilang dari disk diganti
 *      gambar placeholder di path yang sama (GET /documents/{id} berhenti 404). Cuma
 *      dokumen gambar — PDF tidak bisa diganti PNG.
 *   2. Work Order untuk JMTM001 di status yang belum dia punya: `preparing`, pernah
 *      dikembalikan lalu dikerjakan ulang (`in_progress`), dan `completed`.
 *   3. Laporan patroli contoh untuk antrean patroli (PatrolReportSeeder).
 *
 * Juga dipanggil DatabaseSeeder sesudah WorkOrderSeeder, supaya `migrate:fresh --seed`
 * di laptop menghasilkan data yang sama dengan server.
 *
 * Penandanya berawalan '[contoh seeder]' — sama dengan WorkOrderSeeder, jadi kalau dia
 * dijalankan ulang WO di sini ikut dibersihkan lalu dibuat lagi, bukan menumpuk.
 */
class StagingTopUpSeeder extends Seeder
{
    private const PENANDA = '[contoh seeder] JMTM001';

    public function run(): void
    {
        $this->pulihkanBerkas();
        $this->contohPetugas();

        // Antrean patroli staging kosong — seeder ini juga hanya menambah.
        $this->call(PatrolReportSeeder::class);
    }

    private function pulihkanBerkas(): void
    {
        $disk = Storage::disk('local');
        $dipulihkan = 0;

        foreach (Document::where('mime_type', 'like', 'image/%')->cursor() as $doc) {
            if (! $disk->exists($doc->path)) {
                $disk->put($doc->path, PlaceholderImage::png("Dokumen #{$doc->id} - {$doc->document_type}"));
                $dipulihkan++;
            }
        }

        $this->command?->info("Berkas dokumen dipulihkan: {$dipulihkan}");
    }

    private function contohPetugas(): void
    {
        $hendra = User::where('identity_code', 'JMJ001')->firstOrFail();
        $slamet = User::where('identity_code', 'JMTM001')->firstOrFail();
        $rekan = User::where('identity_code', 'JMTM002')->firstOrFail();

        // Disebut namanya seperti WorkOrderSeeder; kalau katalog di server sudah diubah
        // lewat menu master data, ambil baris mana pun — jenis asetnya tidak penting di sini.
        $aset = (AssetCatalog::where('component', 'Guard Rail Beam (4000X312X2.7)')->first()
            ?? AssetCatalog::orderBy('id')->firstOrFail())->id;

        $service = app(WorkOrderService::class);
        $logs = app(WorkOrderLogService::class);

        $terbitkan = function (string $label, array $extra) use ($service, $hendra, $slamet, $rekan, $aset) {
            if (WorkOrder::where('trigger_note', self::PENANDA.' '.$label)->exists()) {
                $this->command?->info("Lewati (sudah ada): {$label}");

                return null;
            }

            return $service->issue($hendra, $extra + [
                'trigger_note' => self::PENANDA.' '.$label,
                'asset_catalog_id' => $aset,
                'location_type' => 'Jalur Utama',
                'route' => 'A',
                'lane' => 'Lajur 1',
                'priority' => 'Sedang',
                'target_date' => now()->addDays(2)->setTime(18, 0)->toDateTimeString(),
                'assignee_ids' => [$slamet->id, $rekan->id],
                'coordinator_id' => $slamet->id,
            ]);
        };

        $tulis = fn (WorkOrder $wo, string $jenis, $jam, array $extra = []) => $logs->sync(
            (string) Str::uuid(), $wo->refresh(), $slamet, $extra + ['entry_type' => $jenis, 'logged_at' => $jam->toDateTimeString()],
        );

        // Mulai + APD + tiga tahap bukti berfoto + ajukan selesai.
        $kerjakan = function (WorkOrder $wo, $mulai) use ($tulis, $slamet) {
            $tulis($wo, 'start', $mulai, ['notes' => 'Tugas diterima, regu menuju gudang.']);
            $tulis($wo, 'apd_check', $mulai->copy()->addMinutes(15), ['apd_items' => config('enums.wo_apd_items')]);

            foreach (['before' => 45, 'during' => 120, 'after' => 240] as $tahap => $menit) {
                $log = $tulis($wo, 'progress', $mulai->copy()->addMinutes($menit), [
                    'bda_stage' => $tahap,
                    'title' => WorkOrderSeeder::JUDUL[$tahap],
                ]);
                WorkOrderSeeder::buktiFoto($log, $slamet, ucfirst($tahap).' - WO '.$wo->reference_number);
            }

            $tulis($wo, 'complete', $mulai->copy()->addMinutes(260));
        };

        // ---- 1. preparing: sudah diterima, APD belum. Titik peta ada.
        if ($wo = $terbitkan('preparing', [
            'trigger_source' => 'Aus Normal',
            'damage_description' => 'Marka jalan KM 12+400 pudar sepanjang ± 50 m.',
            'km' => 12, 'm' => 400,
            'latitude' => -7.6912345, 'longitude' => 110.6012345,
            'priority' => 'Tinggi',
        ])) {
            $tulis($wo, 'start', now()->subHour(), ['notes' => 'Menunggu cat marka dari gudang.']);
        }

        // ---- 2. dikembalikan lalu dikerjakan ulang: in_progress, baru foto before
        //         putaran kedua — ajukan selesai masih harus ditolak (during, after).
        if ($wo = $terbitkan('dikembalikan', [
            'trigger_source' => 'Preventif',
            'damage_description' => 'Pengencangan baut guardrail KM 20+100 s.d. 20+300.',
            'km' => 20, 'm' => 100,
        ])) {
            $kerjakan($wo, now()->subDays(2)->setTime(8, 0));
            $service->review($wo->refresh(), $hendra, [
                'meets_quality_standard' => false,
                'quantity_matches_log' => true,
                'outcome' => 'Dikerjakan Ulang',
                'rejection_notes' => 'Dua baut di sambungan KM 20+250 belum dikencangkan, foto after buram.',
                'revision_deadline' => now()->addDay()->setTime(21, 0)->toDateTimeString(),
            ]);
            // Dimundurkan supaya foto putaran kedua (di bawah) jatuh SESUDAH pengembalian.
            $wo->reviews()->update(['reviewed_at' => now()->subDay()->setTime(9, 0)]);

            $log = $tulis($wo, 'progress', now()->subHours(2), [
                'bda_stage' => 'before',
                'title' => 'Cek ulang sambungan KM 20+250',
            ]);
            WorkOrderSeeder::buktiFoto($log, $slamet, 'Before (ulang) - WO '.$wo->reference_number);
        }

        // ---- 3. completed: ditutup evaluator.
        if ($wo = $terbitkan('selesai', [
            'trigger_source' => 'Temuan Patroli',
            'damage_description' => 'Lampu PJU KM 31+600 mati.',
            'km' => 31, 'm' => 600,
            'route' => 'B',
        ])) {
            $kerjakan($wo, now()->subDays(4)->setTime(9, 0));
            $service->review($wo->refresh(), $hendra, [
                'meets_quality_standard' => true,
                'quantity_matches_log' => true,
                'outcome' => 'Diterima',
                'cost_amount' => 1500000,
            ]);
        }
    }
}
