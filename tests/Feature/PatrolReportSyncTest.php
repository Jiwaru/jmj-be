<?php

namespace Tests\Feature;

use App\Models\AssetCatalog;
use App\Models\AssetType;
use App\Models\Document;
use App\Models\PatrolReport;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatrolReportSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    private AssetCatalog $catalog;

    private string $uuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->officer = $this->user('JMTO001');
        $this->uuid = (string) Str::uuid();

        $type = AssetType::create(['category' => 'road_equipment', 'name' => 'Lampu PJU']);
        $this->catalog = AssetCatalog::create([
            'name' => 'Lampu PJU',
            'asset_type_id' => $type->id,
            'component' => 'Lampu PJU LED 150W',
            'unit_of_measurement' => 'bh',
        ]);
    }

    private function user(string $code, string $role = 'field_officer'): User
    {
        return User::create([
            'identity_code' => $code,
            'name' => "Petugas {$code}",
            'entity' => 'jmto',
            'role' => $role,
            'is_active' => true,
            'password' => 'rahasia',
        ]);
    }

    /** Payload minimal yang sah: patroli tanpa kerusakan. */
    private function payload(array $override = []): array
    {
        return $override + [
            'patrolled_at' => '2026-09-09 06:00:00',
            'route' => 'A',
            'is_damaged' => false,
        ];
    }

    private function damagedPayload(array $override = []): array
    {
        return $this->payload($override + [
            'is_damaged' => true,
            'asset_catalog_id' => $this->catalog->id,
            'location_type' => 'Jalur Utama',
            'km' => 45,
            'm' => 200,
            'lane' => 'Lajur 2',
            'damage_description' => 'Lampu PJU mati, kemungkinan usia pakai.',
        ]);
    }

    private function sync(array $override = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->officer, 'sanctum')
            ->putJson("/api/v1/patrol-reports/{$this->uuid}", $this->payload($override));
    }

    public function test_sync_creates_a_patrol_report_without_damage(): void
    {
        $this->sync()->assertOk()
            ->assertJsonPath('data.report.sync_status', 'sent')
            ->assertJsonPath('data.report.is_damaged', false)
            ->assertJsonPath('data.report.asset_catalog_id', null);
    }

    public function test_damage_fields_are_required_when_damaged(): void
    {
        $this->sync($this->damagedPayload(['asset_catalog_id' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('asset_catalog_id');

        $this->sync($this->damagedPayload())->assertOk();
    }

    /**
     * Kolom kerusakan DILARANG kalau is_damaged=false — pola sama causer_other:
     * dua arah, bukan cuma "wajib kalau ya".
     */
    public function test_damage_fields_are_prohibited_when_not_damaged(): void
    {
        $this->sync(['is_damaged' => false, 'asset_catalog_id' => $this->catalog->id, 'km' => 10])
            ->assertStatus(422)->assertJsonValidationErrors(['asset_catalog_id', 'km']);
    }

    public function test_resending_the_same_uuid_does_not_duplicate(): void
    {
        $this->sync()->assertOk();
        $this->sync()->assertOk();

        $this->assertSame(1, PatrolReport::count());
    }

    public function test_another_officer_cannot_overwrite_someone_elses_report(): void
    {
        $this->sync()->assertOk();

        $this->sync(as: $this->user('JMTO002'))->assertStatus(403);
    }

    public function test_a_report_already_linked_to_a_work_order_can_no_longer_be_changed(): void
    {
        $this->sync($this->damagedPayload())->assertOk();

        $unit = $this->user('JMJ001', 'maintenance_unit');
        WorkOrder::create([
            'reference_number' => '001/JMTM/TM.JOGLO/IX/2026',
            'patrol_report_id' => $this->uuid,
            'asset_catalog_id' => $this->catalog->id,
            'trigger_source' => WorkOrder::TRIGGER_PATROLI,
            'priority' => 'Sedang',
            'damage_description' => 'Lampu PJU mati.',
            'location_type' => 'Jalur Utama',
            'km' => 45,
            'route' => 'A',
            'repair_status' => 'new',
            'created_by' => $unit->id,
        ]);

        $this->sync($this->damagedPayload())->assertStatus(422)
            ->assertJsonPath('errors.patrol_report.0', 'Laporan sudah ditindaklanjuti Unit Maintenance dan tidak bisa diubah lagi.');
    }

    public function test_mcs_supervisor_has_no_access_to_patrol_routes(): void
    {
        $this->sync(as: $this->user('JMTO009', 'mcs_supervisor'))->assertStatus(403);
    }

    public function test_maintenance_unit_can_list_and_view_but_not_sync(): void
    {
        $this->sync($this->damagedPayload())->assertOk();
        $unit = $this->user('JMJ001', 'maintenance_unit');

        $this->actingAs($unit, 'sanctum')->getJson('/api/v1/patrol-reports')
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->actingAs($unit, 'sanctum')->getJson("/api/v1/patrol-reports/{$this->uuid}")
            ->assertOk();

        $this->actingAs($unit, 'sanctum')
            ->putJson("/api/v1/patrol-reports/{$this->uuid}", $this->damagedPayload())
            ->assertStatus(403);
    }

    // ---- Foto bukti kerusakan ---------------------------------------------------

    private function upload(string $fileUuid, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->officer, 'sanctum')
            ->post("/api/v1/patrol-reports/{$this->uuid}/documents", [
                'file_uuid' => $fileUuid,
                'file' => UploadedFile::fake()->image('kerusakan.jpg'),
            ], ['Accept' => 'application/json']);
    }

    public function test_uploading_the_same_file_uuid_twice_stores_one_document(): void
    {
        Storage::fake('local');
        $this->sync($this->damagedPayload())->assertOk();

        $fileUuid = (string) Str::uuid();
        $this->upload($fileUuid)->assertOk();
        $this->upload($fileUuid)->assertOk();

        $this->assertSame(1, Document::count());
        $this->assertSame('patrol_report', Document::first()->documentable_type);
        $this->assertSame('patrol_photo', Document::first()->document_type);
        $this->assertStringStartsWith("patroli/{$this->uuid}/{$fileUuid}.", Document::first()->path);
    }

    public function test_a_file_uuid_that_is_not_a_uuid_is_refused_before_it_touches_a_path(): void
    {
        Storage::fake('local');
        $this->sync($this->damagedPayload())->assertOk();

        $this->upload('../../../etc/passwd')->assertStatus(422);

        $this->assertSame(0, Document::count());
    }

    public function test_another_officer_cannot_upload_to_someone_elses_report(): void
    {
        Storage::fake('local');
        $this->sync($this->damagedPayload())->assertOk();

        $this->upload((string) Str::uuid(), $this->user('JMTO002'))->assertStatus(403);
    }

    /** maintenance_unit boleh melihat foto laporan siapa pun, tidak boleh mengunggah. */
    public function test_maintenance_unit_can_view_but_not_upload_patrol_photos(): void
    {
        Storage::fake('local');
        $this->sync($this->damagedPayload())->assertOk();
        $fileUuid = (string) Str::uuid();
        $this->upload($fileUuid)->assertOk();

        $unit = $this->user('JMJ001', 'maintenance_unit');
        $document = Document::sole();

        $this->actingAs($unit, 'sanctum')->getJson("/api/v1/documents/{$document->id}")->assertOk();

        $this->actingAs($unit, 'sanctum')
            ->post("/api/v1/patrol-reports/{$this->uuid}/documents", [
                'file_uuid' => (string) Str::uuid(),
                'file' => UploadedFile::fake()->image('lain.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    public function test_photos_cannot_be_uploaded_once_a_work_order_exists(): void
    {
        Storage::fake('local');
        $this->sync($this->damagedPayload())->assertOk();

        $unit = $this->user('JMJ001', 'maintenance_unit');
        WorkOrder::create([
            'reference_number' => '001/JMTM/TM.JOGLO/IX/2026',
            'patrol_report_id' => $this->uuid,
            'asset_catalog_id' => $this->catalog->id,
            'trigger_source' => WorkOrder::TRIGGER_PATROLI,
            'priority' => 'Sedang',
            'damage_description' => 'Lampu PJU mati.',
            'location_type' => 'Jalur Utama',
            'km' => 45,
            'route' => 'A',
            'repair_status' => 'new',
            'created_by' => $unit->id,
        ]);

        $this->upload((string) Str::uuid())->assertStatus(422)
            ->assertJsonPath('errors.patrol_report.0', 'Laporan sudah ditindaklanjuti Unit Maintenance, tidak bisa diunggah foto baru.');
    }
}
