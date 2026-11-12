<?php

namespace Tests\Feature;

use App\Models\AssetCatalog;
use App\Models\AssetType;
use App\Models\CatalogPrice;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderReview;
use App\Services\Mcs\IncidentVerificationService;
use App\Services\Shared\IncidentReportSyncService;
use App\Services\Shared\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Notifikasi tenggat dari scheduler (`notifications:deadlines`). Yang dikunci: berbunyi
 * di waktu yang benar, ke orang yang benar, dan TIDAK berbunyi ulang tiap jam.
 */
class DeadlineNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    private User $mcs;

    private User $unit;

    private User $petugas;

    private CatalogPrice $price;

    protected function setUp(): void
    {
        parent::setUp();

        $this->officer = $this->user('JMTO001', 'field_officer');
        $this->mcs = $this->user('JMTO003', 'mcs_supervisor');
        $this->unit = $this->user('JMJ001', 'maintenance_unit');
        $this->petugas = $this->user('JMTM001', 'maintenance_officer');

        $type = AssetType::create(['category' => 'support_facility', 'name' => 'Guardrail Kendaraan Tipe A']);
        $catalog = AssetCatalog::create([
            'name' => 'Guard Rail Beam',
            'asset_type_id' => $type->id,
            'component' => 'Guard Rail Beam (4000X312X2.7)',
            'unit_of_measurement' => 'bh',
        ]);
        $this->price = CatalogPrice::create([
            'asset_catalog_id' => $catalog->id,
            'min_price' => 1855962.44,
            'max_price' => 1976600,
            'effective_from' => '2026-01-01',
            'change_reason' => 'admin_revision',
            'changed_by' => $this->officer->id,
        ]);

        Storage::fake('local');
    }

    public function test_tenggat_surat_keringanan_berbunyi_sekali_mendekat_dan_sekali_lewat(): void
    {
        $this->laporanKeringananDisahkan();
        $hari = (int) config('toll.relief_grace_days');

        $this->jalankan();
        $this->assertSame([], $this->events($this->mcs, 'relief_'));

        $this->travel($hari)->days();
        $this->travel(-2)->hours();
        $this->jalankan();
        $this->jalankan();
        $this->assertSame(['relief_deadline_near'], $this->events($this->mcs, 'relief_'));

        $this->travel(3)->hours();
        $this->jalankan();
        $this->jalankan();
        $this->assertEqualsCanonicalizing(['relief_deadline_near', 'relief_deadline_passed'], $this->events($this->mcs, 'relief_'));
        $this->assertSame([], $this->events($this->officer, 'relief_'));
    }

    public function test_surat_keringanan_yang_sudah_masuk_tidak_berbunyi(): void
    {
        $report = $this->laporanKeringananDisahkan();
        $report->documents()->create([
            'documentable_type' => 'incident_report',
            'document_type' => 'relief_letter',
            'path' => "insiden/{$report->uuid}/surat.pdf",
            'mime_type' => 'application/pdf',
            'size_bytes' => 1,
            'uploaded_by' => $this->mcs->id,
            'uploaded_at' => now(),
        ]);

        $this->travel((int) config('toll.relief_grace_days') + 1)->days();
        $this->jalankan();

        $this->assertSame([], $this->events($this->mcs, 'relief_'));
    }

    public function test_wo_lewat_target_berbunyi_sekali_ke_unit(): void
    {
        $this->terbitkan(Carbon::now()->addHour());

        $this->jalankan();
        $this->assertSame([], $this->events($this->unit, 'target_'));

        $this->travel(2)->hours();
        $this->jalankan();
        $this->jalankan();

        $this->assertSame(['target_overdue'], $this->events($this->unit, 'target_'));
        $this->assertSame([], $this->events($this->petugas, 'target_'));
    }

    public function test_target_revisi_lewat_berbunyi_per_putaran_evaluasi(): void
    {
        $wo = $this->terbitkan(Carbon::now()->addMonth());
        $wo->update(['repair_status' => 'in_progress']);

        $this->kembalikan($wo, Carbon::now()->addHour());
        $this->travel(2)->hours();
        $this->jalankan();
        $this->jalankan();

        $this->assertSame(['revision_overdue'], $this->events($this->petugas, 'revision_'));
        $this->assertSame(['revision_overdue'], $this->events($this->unit, 'revision_'));

        // Dikembalikan lagi dengan target baru — target kedua tetap berbunyi.
        $this->travel(1)->minutes();
        $this->kembalikan($wo, Carbon::now()->addHour());
        $this->travel(2)->hours();
        $this->jalankan();

        $this->assertSame(['revision_overdue', 'revision_overdue'], $this->events($this->petugas, 'revision_'));

        // Sudah diajukan selesai lagi → tidak dipantau.
        $wo->update(['repair_status' => 'submitted']);
        $this->kembalikan($wo, Carbon::now()->subHour(), status: 'submitted');
        $this->jalankan();
        $this->assertCount(2, $this->events($this->petugas, 'revision_'));
    }

    // ---- Penolong --------------------------------------------------------------

    private function jalankan(): void
    {
        $this->artisan('notifications:deadlines')->assertSuccessful();
    }

    private function events(User $user, string $awalan): array
    {
        return $user->notifications()->get()->pluck('data.event')
            ->filter(fn ($e) => str_starts_with($e, $awalan))->values()->all();
    }

    private function laporanKeringananDisahkan()
    {
        $report = app(IncidentReportSyncService::class)->upsert((string) Str::uuid(), $this->officer, [
            'date_time' => '2026-08-21 14:20:00',
            'km' => 12, 'm' => 300, 'route' => 'A', 'lane' => 'Lajur 1',
            'causer' => 'Pecah Ban',
            'analyze' => 'Rusak Berat',
            'is_identified' => true,
            'officer_2_id' => $this->user('JMTO002', 'field_officer')->id,
            'settlement_type' => 'Tunai / Transfer',
            'relief_requested' => true,
            'culprits' => [[
                'full_name' => 'Rian Hidayat',
                'party_role' => 'Pengemudi',
                'national_id' => '3271902805920001',
                'driver_license_number' => '3471010101010001',
            ]],
            'damaged_items' => [[
                'asset_catalog_id' => $this->price->asset_catalog_id,
                'catalog_price_id' => $this->price->id,
                'quantity' => 2,
                'damage_percentage' => 100,
            ]],
        ]);

        $verifikasi = app(IncidentVerificationService::class);
        $verifikasi->validateIncidentBa($report, $this->mcs, []);
        $verifikasi->validateDamageBa($report->refresh(), $this->mcs);

        return $report->refresh();
    }

    private function terbitkan(Carbon $target): WorkOrder
    {
        return app(WorkOrderService::class)->issue($this->unit, [
            'trigger_source' => 'Temuan Patroli',
            'asset_catalog_id' => $this->price->asset_catalog_id,
            'damage_description' => 'Lampu PJU KM 45+200 mati.',
            'location_type' => 'Jalur Utama',
            'km' => 45, 'm' => 200, 'route' => 'B', 'lane' => 'Bahu Jalan',
            'priority' => 'Sedang',
            'target_date' => $target->toDateTimeString(),
            'assignee_ids' => [$this->petugas->id],
            'coordinator_id' => $this->petugas->id,
        ]);
    }

    private function kembalikan(WorkOrder $wo, Carbon $tenggat, string $status = 'in_progress'): void
    {
        WorkOrderReview::create([
            'work_order_id' => $wo->id,
            'reviewer_id' => $this->unit->id,
            'meets_quality_standard' => false,
            'quantity_matches_log' => true,
            'outcome' => 'Dikerjakan Ulang',
            'rejection_notes' => 'Baut kurang satu.',
            'revision_deadline' => $tenggat,
            'reviewed_at' => now(),
        ]);
        $wo->update(['repair_status' => $status]);
    }

    private function user(string $code, string $role): User
    {
        return User::create([
            'identity_code' => $code,
            'name' => "Orang {$code}",
            'position' => 'Petugas',
            'entity' => 'jmto',
            'role' => $role,
            'is_active' => true,
            'password' => 'rahasia',
        ]);
    }
}
