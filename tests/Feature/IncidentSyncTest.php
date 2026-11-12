<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AssetCatalog;
use App\Models\AssetType;
use App\Models\CatalogPrice;
use App\Models\Culprit;
use App\Models\Document;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class IncidentSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    private User $officer2;

    private CatalogPrice $price;

    private string $uuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->officer = $this->user('JMTO001');
        $this->officer2 = $this->user('JMTO002');
        $this->uuid = (string) Str::uuid();

        $type = AssetType::create(['category' => 'road_equipment', 'name' => 'Guard Rail']);
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

    /** Payload minimal yang sah: satu kerusakan, tanpa pelaku (tabrak lari). */
    private function payload(array $override = []): array
    {
        return $override + [
            'date_time' => '2026-08-21 14:20:00',
            'km' => 12,
            'm' => 300,
            'route' => 'A',
            'lane' => 'Lajur 1',
            'weather_condition' => 'Cerah',
            'causer' => 'Pecah Ban',
            'action' => ['Koordinasi Unit Derek', 'Pembersihan Lajur dari Puing'],
            'analyze' => 'Rusak Berat',
            'conclusion' => 'Terbitkan Work Order Penggantian Aset',
            'is_identified' => false,
            'officer_2_id' => $this->officer2->id,
            'team' => [$this->officer->id],
            'vehicles' => [['vehicle_type' => 'Sedan', 'vehicle_group' => 'Golongan I', 'plate_number' => 'AB 1234 XY']],
            'damaged_items' => [[
                'asset_catalog_id' => $this->price->asset_catalog_id,
                'catalog_price_id' => $this->price->id,
                'quantity' => 2,
                'damage_percentage' => 100,
                'subtotal' => 1,   // sengaja ngawur — server harus mengabaikannya
            ]],
        ];
    }

    private function sync(array $override = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->officer, 'sanctum')
            ->putJson("/api/v1/incident-reports/{$this->uuid}", $this->payload($override));
    }

    public function test_bootstrap_needs_auth_and_returns_the_offline_bundle(): void
    {
        $this->getJson('/api/v1/sync/bootstrap')->assertStatus(401);

        $this->actingAs($this->officer, 'sanctum')->getJson('/api/v1/sync/bootstrap')
            ->assertOk()
            ->assertJsonCount(1, 'data.asset_types')
            ->assertJsonCount(1, 'data.asset_catalog')
            ->assertJsonCount(1, 'data.catalog_prices')
            ->assertJsonPath('data.officers.0.identity_code', 'JMTO001')
            // 12 sejak Fase 2: party_role dan location_type ikut ke bundel luring.
            ->assertJsonCount(12, 'data.options')
            ->assertJsonPath('data.options.causer.6', 'Lainnya');
    }

    /**
     * `ba_officers` (redesain 16 Sep) itu daftar TERPISAH dari `officers` — beda
     * predikat, beda tujuan. `officers` isinya SEMUA entity jmto (termasuk MCS,
     * buat mengisi `team`); `ba_officers` cuma `field_officer` aktif (buat
     * `officer_2_id`, penanda tangan BA). Test ini yang jatuh kalau dua daftar itu
     * suatu saat disatukan diam-diam.
     */
    public function test_bootstrap_ba_officers_excludes_mcs_even_though_officers_includes_it(): void
    {
        $this->user('JMTO003', 'mcs_supervisor');

        $this->actingAs($this->officer, 'sanctum')->getJson('/api/v1/sync/bootstrap')
            ->assertOk()
            ->assertJsonCount(3, 'data.officers')
            ->assertJsonCount(2, 'data.ba_officers')
            ->assertJsonPath('data.ba_officers.0.identity_code', 'JMTO001')
            ->assertJsonPath('data.ba_officers.1.identity_code', 'JMTO002');
    }

    public function test_sync_creates_report_without_number_and_queues_it_for_mcs(): void
    {
        $this->sync()->assertOk()
            ->assertJsonPath('data.report.sync_status', 'sent')
            ->assertJsonPath('data.report.report_status', 'pending_verification')
            ->assertJsonPath('data.report.report_number', null);
    }

    public function test_server_recomputes_subtotal_and_ignores_the_number_the_phone_sent(): void
    {
        $this->sync();

        $report = IncidentReport::find($this->uuid);

        // 1.976.600 (max_price) x 2 x 100% — bukan 1 yang dikirim HP
        $this->assertEquals('3953200.00', $report->damagedItems->first()->subtotal);
        $this->assertEquals('3953200.00', $report->total_estimation);
    }

    public function test_resending_the_same_uuid_does_not_duplicate_anything(): void
    {
        $this->sync()->assertOk();
        $this->sync()->assertOk();

        $this->assertSame(1, IncidentReport::count());

        $report = IncidentReport::find($this->uuid);
        $this->assertCount(1, $report->damagedItems);
        $this->assertCount(1, $report->involvedVehicles);
        $this->assertCount(1, $report->operationalTeam);
    }

    // ---- Integritas waktu laporan ----------------------------------------------

    /**
     * Laporan yang mendarat jam 10:03 mengaku terjadi jam 18:00 datang lima jam sebelum
     * kejadiannya sendiri. Itu bukan "mencurigakan", itu tidak mungkin — dan satu-satunya
     * bagian kasus ini yang boleh diputuskan server sendiri. Sisanya penilaian MCS.
     */
    public function test_an_incident_claiming_to_happen_in_the_future_is_refused(): void
    {
        $this->sync(['date_time' => now()->addDay()->toDateTimeString()])
            ->assertStatus(422)->assertJsonValidationErrors('date_time');

        $this->assertSame(0, IncidentReport::count());
    }

    /**
     * Dan offline-first TIDAK ikut kena. Ini yang membedakan aturan di atas dari sekadar
     * mempersempit: laporan yang diketik di bahu jalan kemarin lalu baru tersinkron hari
     * ini tetap lolos, karena kemarin itu masa lalu.
     */
    public function test_a_report_typed_yesterday_and_synced_today_still_gets_through(): void
    {
        $this->sync(['date_time' => now()->subDay()->toDateTimeString()])->assertOk();
    }

    /**
     * Sync PERTAMA sengaja tidak dicatat: user_id, created_at, dan date_time sudah
     * menyimpan siapa, kapan mengirim, dan jam berapa yang diklaim. Baris log di situ
     * murni duplikat — dan duplikat itu yang belakangan melenceng.
     *
     * Yang benar-benar tidak punya kolom adalah jam yang diklaim SEBELUMNYA: laporan
     * yang ditolak lalu dikirim ulang dengan date_time berbeda menimpa nilai lama tanpa
     * bekas, dan itu persis manuver yang perlu bisa ditelusuri.
     */
    public function test_only_a_changed_incident_hour_leaves_a_trail(): void
    {
        $this->sync()->assertOk();
        $this->assertSame(0, ActivityLog::where('action', 'incident_time_changed')->count());

        $this->sync(['date_time' => '2026-08-21 18:00:00'])->assertOk();

        $log = ActivityLog::where('action', 'incident_time_changed')->sole();
        $this->assertSame('incident_report', $log->auditable_type);
        $this->assertSame($this->uuid, $log->auditable_id);
        $this->assertSame($this->officer->id, $log->user_id);
        $this->assertSame('2026-08-21 14:20:00', $log->old_values['date_time']);
        $this->assertSame('2026-08-21 18:00:00', $log->new_values['date_time']);
    }

    /**
     * Kirim ulang karena sinyal putus itu hari kerja normal, bukan peristiwa. Yang
     * menangkap ini kalau nanti equalTo() diganti !== : dua objek Carbon bernilai sama
     * tidak pernah identik, jadi tiap retry akan menerbitkan barisnya sendiri.
     */
    public function test_resending_the_same_hour_adds_no_second_trail(): void
    {
        $this->sync()->assertOk();
        $this->sync()->assertOk();
        $this->sync()->assertOk();

        $this->assertSame(0, ActivityLog::where('action', 'incident_time_changed')->count());
    }

    public function test_the_list_filters_by_report_status_and_keeps_its_page_info(): void
    {
        $this->sync()->assertOk();

        $this->actingAs($this->officer, 'sanctum')->getJson('/api/v1/incident-reports')
            ->assertOk()
            ->assertJsonCount(1, 'data')          // baris ada di data, bukan data.data
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.current_page', 1);

        $this->actingAs($this->officer, 'sanctum')
            ->getJson('/api/v1/incident-reports?report_status=rejected')
            ->assertOk()->assertJsonPath('meta.total', 0);

        // sync_status bukan filter yang sah — di server nilainya selalu 'sent'.
        $this->actingAs($this->officer, 'sanctum')
            ->getJson('/api/v1/incident-reports?report_status=draft')
            ->assertStatus(422);
    }

    public function test_another_officer_cannot_overwrite_someone_elses_report(): void
    {
        $this->sync()->assertOk();

        // JMTO002 sudah dipakai $this->officer2 (penanda tangan 2 default) —
        // "orang lain" di sini butuh identity_code yang belum dipakai.
        $this->sync(as: $this->user('JMTO099'))->assertStatus(403);
    }

    public function test_a_validated_report_can_no_longer_be_changed(): void
    {
        $this->sync()->assertOk();
        IncidentReport::where('uuid', $this->uuid)->update(['report_status' => 'verified']);

        $this->sync()->assertStatus(422)
            ->assertJsonPath('errors.report_status.0', 'Laporan sudah disahkan dan tidak bisa diubah lagi.');
    }

    public function test_a_supervisor_is_not_allowed_on_the_field_officer_routes(): void
    {
        $this->sync(as: $this->user('JMTO009', 'mcs_supervisor'))->assertStatus(403);
    }

    public function test_a_price_belonging_to_another_asset_is_refused(): void
    {
        $other = AssetCatalog::create([
            'name' => 'Mur Baut',
            'asset_type_id' => $this->price->assetCatalog->asset_type_id,
            'unit_of_measurement' => 'bh',
        ]);

        $this->sync(['damaged_items' => [[
            'asset_catalog_id' => $other->id,
            'catalog_price_id' => $this->price->id,
            'quantity' => 1,
            'damage_percentage' => 100,
        ]]])->assertStatus(422)->assertJsonStructure(['errors' => ['damaged_items']]);
    }

    public function test_an_asset_without_a_price_fails_loudly_instead_of_costing_zero(): void
    {
        $this->price->update(['max_price' => null]);

        $this->sync()->assertStatus(422)->assertJsonStructure(['errors' => ['damaged_items']]);
        $this->assertSame(0, IncidentReport::count());
    }

    public function test_damage_rows_contradicting_the_damage_analysis_are_refused(): void
    {
        $this->sync(['analyze' => 'Tidak Ada Kerusakan Aset'])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['damaged_items']]);
    }

    /**
     * Arah sebaliknya, dan ini yang lebih mahal kalau bocor: `analyze` yang menyatakan
     * ada kerusakan menurunkan `is_asset_broken = true`, jadi BA Kerusakan wajib terbit.
     * Dengan nol baris kerusakan, yang terbit BA resmi bernomor bertabel kosong senilai
     * Rp 0 — dan antrean Work Order (`damaged_items.work_order_id IS NULL`) tidak pernah
     * memuat asetnya, jadi yang dinyatakan Rusak Berat tidak diperbaiki siapa pun.
     */
    public function test_a_damage_analysis_without_any_damage_row_is_refused(): void
    {
        $this->sync(['damaged_items' => []])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['damaged_items']]);

        $this->assertSame(0, IncidentReport::count());
    }

    /**
     * km + route wajib, m + lane boleh kosong. Pasangan (route, km) itu jangkar
     * analisis titik rawan dan yang dipakai mengirim regu perbaikan; kosong berarti
     * BA mencetak "KM ... + ... jalur ..." kosong di dokumen yang dipakai menagih.
     * Meter dan lajur bisa benar-benar tidak diketahui pasti di kejadian yang ramai —
     * tebakan yang dipaksa lebih buruk daripada slot yang dilingkari tangan.
     */
    public function test_km_and_route_are_required_but_meter_and_lane_are_not(): void
    {
        $this->sync(['km' => null])->assertStatus(422)->assertJsonValidationErrors('km');
        $this->sync(['route' => null])->assertStatus(422)->assertJsonValidationErrors('route');

        $this->sync(['m' => null, 'lane' => null])->assertOk();
    }

    /**
     * GPS HP — sumber titik peta Work Order. Opsional (GPS bisa mati), tapi sepasang:
     * lintang tanpa bujur bukan titik.
     */
    public function test_gps_is_optional_but_must_come_as_a_pair(): void
    {
        $this->sync(['latitude' => -7.56])->assertStatus(422)->assertJsonValidationErrors('longitude');
        $this->sync(['latitude' => -95, 'longitude' => 110.7])->assertStatus(422)->assertJsonValidationErrors('latitude');

        $this->sync(['latitude' => -7.5612345, 'longitude' => 110.7712345])->assertOk();
        $this->assertSame(-7.5612345, IncidentReport::find($this->uuid)->latitude);
    }

    /** 0% berarti asetnya tidak rusak — barisnya tidak punya alasan ada, dan Rp 0 tetap masuk antrean WO. */
    public function test_a_zero_percent_damage_row_is_refused(): void
    {
        $this->sync(['damaged_items' => [[
            'asset_catalog_id' => $this->price->asset_catalog_id,
            'catalog_price_id' => $this->price->id,
            'quantity' => 2,
            'damage_percentage' => 0,
        ]]])->assertStatus(422)->assertJsonStructure(['errors' => ['damaged_items.0.damage_percentage']]);
    }

    /**
     * Regu insiden itu petugas JMTO yang hadir di TKP — batasnya sama persis dengan
     * daftar `officers` di sync/bootstrap. Daftar di HP sudah tersaring, tapi daftar
     * di HP bukan penjaga: yang mengirim request itu APK di HP orang.
     */
    public function test_the_crew_only_accepts_active_jmto_users(): void
    {
        $unit = User::create([
            'identity_code' => 'JMJ001', 'name' => 'Unit Maintenance', 'entity' => 'jmj',
            'role' => 'maintenance_unit', 'is_active' => true,
            'password' => 'rahasia',
        ]);

        $this->sync(['team' => [$unit->id]])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['team.0']]);

        // Petugas LAIN yang dinonaktifkan, bukan petugas yang sedang login.
        //
        // Sebelumnya test ini menonaktifkan `$this->officer` sendiri sebagai jalan pintas.
        // Sejak middleware `active` dipasang itu tidak bisa lagi: request-nya dijawab 401
        // sebelum validasi sempat jalan, jadi yang teruji bukan lagi penjaga `team`
        // melainkan penjaga token. Yang dimaksud test ini tetap yang pertama.
        $nonaktif = User::create([
            'identity_code' => 'JMTO099', 'name' => 'Petugas Nonaktif', 'entity' => 'jmto',
            'role' => 'field_officer', 'is_active' => false,
            'password' => 'rahasia',
        ]);

        $this->sync(['team' => [$nonaktif->id]])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['team.0']]);
    }

    /**
     * Penanda tangan 2 BA Kejadian (redesain 16 Sep) — wajib, dan predikatnya BEDA
     * dari `team` di atas: `role = field_officer` khusus (bukan `entity = jmto`
     * saja), karena MCS tidak boleh masuk slot penanda tangan sama sekali (SK).
     * Dipindah dari IncidentVerificationTest — dulu ini aturan di endpoint
     * `incident-ba/validate`, sekarang di endpoint sync ini.
     */
    public function test_officer_2_id_is_required_and_must_be_an_active_field_officer(): void
    {
        $this->sync(['officer_2_id' => null])->assertStatus(422)
            ->assertJsonValidationErrors('officer_2_id');

        $mcs = $this->user('JMTO009', 'mcs_supervisor');
        $this->sync(['officer_2_id' => $mcs->id])->assertStatus(422)
            ->assertJsonValidationErrors('officer_2_id');

        $nonaktif = $this->user('JMTO010');
        $nonaktif->update(['is_active' => false]);
        $this->sync(['officer_2_id' => $nonaktif->id])->assertStatus(422)
            ->assertJsonValidationErrors('officer_2_id');
    }

    /** Pelapor tidak bisa menunjuk dirinya sendiri sebagai penanda tangan kedua. */
    public function test_officer_2_id_cannot_be_the_reporter_themselves(): void
    {
        $this->sync(['officer_2_id' => $this->officer->id])->assertStatus(422)
            ->assertJsonValidationErrors('officer_2_id');
    }

    /**
     * Nilai di luar daftar ditolak, bukan disimpan apa adanya. Dulu kolom-kolom ini
     * ketikan bebas, jadi apa pun masuk — termasuk 'Guardrail' dan 'Guard Rail'
     * berdampingan untuk benda yang sama.
     */
    public function test_values_outside_the_option_list_are_refused(): void
    {
        $this->sync(['causer' => 'Ngantuk berat'])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['causer']]);

        $this->sync(['action' => ['Beli kopi']])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['action.0']]);

        $this->sync(['vehicles' => [['vehicle_type' => 'class_1', 'vehicle_group' => 'Gol I', 'plate_number' => 'AB 1 X']]])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['vehicles.0.vehicle_type', 'vehicles.0.vehicle_group']]);

        $this->assertSame(0, IncidentReport::count());
    }

    /**
     * BAK yang cuma melaporkan kejadian (6 Okt). 'Tidak Ada' harus lolos sampai ke
     * database — CHECK-nya dibangun dari config saat migrate, jadi tanpa migration
     * nilainya lolos validasi lalu jadi 500 — dan tidak boleh digabung tindakan lain.
     */
    public function test_tindakan_tidak_ada_diterima_tapi_harus_berdiri_sendiri(): void
    {
        $this->sync(['action' => ['Tidak Ada', 'Koordinasi Unit Derek']])->assertStatus(422)
            ->assertJsonValidationErrors('action');
        $this->assertSame(0, IncidentReport::count());

        $this->sync(['action' => ['Tidak Ada']])->assertOk();
        $this->assertSame(['Tidak Ada'], IncidentReport::sole()->action);
    }

    /**
     * 'Lainnya' dan teksnya terikat dua arah. Satu arah saja tidak cukup: teks lama
     * akan nyangkut setelah petugas mengganti pilihannya ke kategori betulan, dan
     * BA mencetak penyebab yang sudah tidak dipilih siapa pun.
     */
    public function test_other_cause_needs_its_text_and_refuses_it_otherwise(): void
    {
        $this->sync(['causer' => 'Lainnya'])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['causer_other']]);

        $this->sync(['causer' => 'Pecah Ban', 'causer_other' => 'Ban belakang meletus'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['causer_other']]);

        $this->sync(['causer' => 'Lainnya', 'causer_other' => 'Tumpahan oli dari truk lain'])
            ->assertOk();

        $this->assertSame('Tumpahan oli dari truk lain', IncidentReport::first()->causer_other);
    }

    /**
     * is_asset_broken tidak lagi datang dari HP — diturunkan server dari `analyze`,
     * supaya dua sumber untuk satu fakta tidak bisa bertentangan. Kolomnya tetap
     * terisi karena MCS membacanya di Fase 2.1.
     */
    public function test_the_server_derives_the_broken_flag_from_the_damage_analysis(): void
    {
        $this->sync(['analyze' => 'Tidak Ada Kerusakan Aset', 'damaged_items' => []])->assertOk();
        $this->assertFalse(IncidentReport::first()->is_asset_broken);

        // Nilai kiriman HP diabaikan, bukan dipercaya.
        $this->sync(['is_asset_broken' => false])->assertOk();
        $this->assertTrue(IncidentReport::first()->is_asset_broken);

        // Pilihan jamak bulak-balik lewat jsonb tanpa berubah bentuk.
        $this->assertSame(
            ['Koordinasi Unit Derek', 'Pembersihan Lajur dari Puing'],
            IncidentReport::first()->action,
        );
    }

    /**
     * Satu kartu "Pihak N" di layar = satu pelaku + satu kendaraan. HP merujuk
     * kendaraannya lewat posisi array karena id-nya baru lahir di server.
     */
    public function test_a_culprit_points_at_the_vehicle_they_drove(): void
    {
        $this->sync([
            'is_identified' => true,
            'vehicles' => [
                ['vehicle_type' => 'Sedan', 'vehicle_group' => 'Golongan I', 'plate_number' => 'AB 1 XY'],
                ['vehicle_type' => 'Truk Box', 'vehicle_group' => 'Golongan III', 'plate_number' => 'B 9123 AB'],
            ],
            'culprits' => [
                ['full_name' => 'Rian Hidayat', 'vehicle_index' => 1],
                ['full_name' => 'Budi Santoso', 'vehicle_index' => 0],
            ],
        ])->assertOk();

        $rian = Culprit::where('full_name', 'Rian Hidayat')->sole();
        $budi = Culprit::where('full_name', 'Budi Santoso')->sole();

        // Indeks 1 harus jadi truk, bukan sedan — kalau urutan insert bergeser,
        // pelaku menunjuk kendaraan orang lain dan tidak ada yang protes.
        $this->assertSame('B 9123 AB', $rian->involvedVehicle->plate_number);
        $this->assertSame('AB 1 XY', $budi->involvedVehicle->plate_number);
    }

    /**
     * Pelaku tanpa TAUTAN kendaraan tetap sah — pemilik yang tidak ikut mengemudi
     * memang tidak mengemudikan apa pun, dan ERD membolehkan kolomnya null.
     *
     * Kendaraannya sendiri tetap wajib tercatat: yang diminta BA Kerusakan kendaraan
     * yang TERLIBAT, dan BaPdfService jatuh ke kendaraan pertama laporan.
     */
    public function test_a_culprit_without_a_vehicle_link_is_allowed(): void
    {
        $this->sync([
            'is_identified' => true,
            'culprits' => [['full_name' => 'Rian Hidayat']],
        ])->assertOk();

        $this->assertNull(Culprit::sole()->involved_vehicle_id);
    }

    /**
     * Nol kendaraan pada laporan yang pelakunya teridentifikasi ditolak: slot
     * "kendaraan jenis ...... no polisi ......" di BA Kerusakan tidak punya apa pun
     * untuk diisi, dan kendaraan itulah yang ditahan sampai ganti ruginya beres.
     */
    public function test_an_identified_culprit_without_any_vehicle_is_refused(): void
    {
        $this->sync([
            'is_identified' => true,
            'vehicles' => [],
            'culprits' => [['full_name' => 'Rian Hidayat']],
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['vehicles']]);

        $this->assertSame(0, IncidentReport::count());
    }

    /**
     * Indeks di luar jangkauan harus 422, bukan 500. Tanpa penjagaan ini service
     * mengambil kendaraan yang tidak ada dan laporan petugas mati tanpa pesan.
     */
    public function test_a_vehicle_index_out_of_range_is_refused(): void
    {
        $this->sync([
            'is_identified' => true,
            'culprits' => [['full_name' => 'Rian Hidayat', 'vehicle_index' => 5]],
        ])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['culprits.0.vehicle_index']]);

        $this->assertSame(0, IncidentReport::count());
    }

    public function test_uploading_the_same_file_uuid_twice_stores_one_document(): void
    {
        Storage::fake('local');
        $this->sync()->assertOk();

        $fileUuid = (string) Str::uuid();
        $upload = fn () => $this->actingAs($this->officer, 'sanctum')
            ->post("/api/v1/incident-reports/{$this->uuid}/documents", [
                'file_uuid' => $fileUuid,
                'document_type' => 'scene_photo',
                'file' => UploadedFile::fake()->image('tkp.jpg'),
            ], ['Accept' => 'application/json']);

        $upload()->assertOk();
        $upload()->assertOk();

        $this->assertSame(1, Document::count());
        $this->assertStringStartsWith("insiden/{$this->uuid}/{$fileUuid}.", Document::first()->path);
    }

    public function test_a_file_uuid_that_is_not_a_uuid_is_refused_before_it_touches_a_path(): void
    {
        Storage::fake('local');
        $this->sync()->assertOk();

        $this->actingAs($this->officer, 'sanctum')
            ->post("/api/v1/incident-reports/{$this->uuid}/documents", [
                'file_uuid' => '../../../etc/passwd',
                'document_type' => 'scene_photo',
                'file' => UploadedFile::fake()->image('tkp.jpg'),
            ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, Document::count());
    }
}
