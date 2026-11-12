<?php

namespace Tests\Feature;

use App\Models\AssetCatalog;
use App\Models\AssetType;
use App\Models\CatalogPrice;
use App\Models\IncidentReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Shared\IncidentReportSyncService;
use App\Services\Shared\PatrolReportSyncService;
use App\Services\Mcs\IncidentVerificationService;
use App\Services\Shared\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Empat kartu angka di Dashboard Unit Maintenance.
 *
 * Keempatnya menghitung tahap yang BERBEDA dari satu pekerjaan yang sama, jadi satu WO
 * tidak boleh muncul di dua kartu sekaligus — kecuali "Lewat Target", yang memang
 * memotong ketiganya secara melintang.
 */
class MaintenanceDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $officer1;

    private User $officer2;

    private User $mcs;

    private User $unit;

    private User $petugas;

    private CatalogPrice $price;

    private AssetCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->officer1 = $this->user('JMTO001');
        $this->officer2 = $this->user('JMTO002');
        $this->mcs = $this->user('JMTO003', 'mcs_supervisor');
        $this->unit = $this->user('JMJ001', 'maintenance_unit');
        $this->petugas = $this->user('JMTM001', 'maintenance_officer');

        $type = AssetType::create(['category' => 'support_facility', 'name' => 'Guardrail Kendaraan Tipe A']);
        $this->catalog = AssetCatalog::create([
            'name' => 'Guard Rail Beam',
            'asset_type_id' => $type->id,
            'component' => 'Guard Rail Beam (4000X312X2.7)',
            'unit_of_measurement' => 'bh',
        ]);
        $this->price = CatalogPrice::create([
            'asset_catalog_id' => $this->catalog->id,
            'min_price' => 1855962.44,
            'max_price' => 1976600,
            'effective_from' => '2026-01-01',
            'change_reason' => 'admin_revision',
            'changed_by' => $this->officer1->id,
        ]);

        Storage::fake('local');
    }

    public function test_dashboard_kosong_menjawab_nol_bukan_null(): void
    {
        $this->assertSame(
            ['repair_queue' => 0, 'patrol_queue' => 0, 'active_work_orders' => 0, 'pending_review' => 0, 'overdue' => 0],
            $this->summary(),
        );
    }

    /**
     * Empat tahap, empat angka. Kalau dua kartu selalu sama, salah satunya menghitung hal
     * yang sama dua kali dan layarnya menampilkan satu fakta dengan dua nama.
     */
    public function test_tiap_kartu_menghitung_tahap_yang_berbeda(): void
    {
        // Menunggu diterbitkan WO-nya.
        $this->laporanTervalidasi();

        // Sudah terbit, belum diserahkan.
        $this->terbitkan();

        // Regu sudah mengajukan selesai.
        $this->terbitkan()->update(['repair_status' => 'submitted']);

        // Sudah ditutup — tidak masuk kartu mana pun.
        $this->terbitkan()->update(['repair_status' => 'completed']);

        $summary = $this->summary();

        $this->assertSame(1, $summary['repair_queue']);
        $this->assertSame(1, $summary['active_work_orders']);
        $this->assertSame(1, $summary['pending_review']);
        $this->assertSame(0, $summary['overdue']);
    }

    /**
     * Antrean dihitung per TITIK, bukan per baris kerusakan. Empat guardrail rusak di KM
     * yang sama itu satu kunjungan regu — dihitung empat, Unit Maintenance mengira ada
     * empat pekerjaan dan menerbitkan empat Work Order untuk satu lokasi.
     */
    public function test_antrean_dihitung_per_titik_bukan_per_baris_kerusakan(): void
    {
        $this->laporanTervalidasi(['damaged_items' => $this->items(4)]);

        $this->assertSame(1, $this->summary()['repair_queue']);
    }

    /**
     * Laporan yang BA Kerusakannya belum disahkan BUKAN antrean pekerjaan. Pemicunya
     * validasi BA Kerusakan — kalau kartu ini ikut menghitung yang belum sah, Unit
     * Maintenance mengerjakan kerusakan yang angkanya masih bisa berubah.
     */
    public function test_antrean_tidak_menghitung_laporan_yang_ba_kerusakannya_belum_sah(): void
    {
        $belumSah = $this->laporan();
        $this->sahkanBaKejadian($belumSah);

        $this->assertSame(0, $this->summary()['repair_queue']);
    }

    /**
     * "Lewat Target" memotong melintang: yang dihitung tanggalnya, bukan tahapnya. WO yang
     * sudah ditutup tidak ikut walau targetnya lewat — pekerjaannya sudah selesai, terlambat
     * atau tidak.
     */
    public function test_lewat_target_menghitung_yang_belum_selesai_saja(): void
    {
        $terlambat = $this->terbitkan();
        $terlambat->update(['target_date' => Carbon::yesterday()->toDateString()]);

        $terlambatTapiSelesai = $this->terbitkan();
        $terlambatTapiSelesai->update([
            'target_date' => Carbon::yesterday()->toDateString(),
            'repair_status' => 'completed',
        ]);

        // Sejak 1 Okt targetnya BERJAM: sejam lagi belum terlambat, walau tanggalnya sama
        // dengan yang sejam lalu — yang sejam lalu sudah terlambat.
        $this->terbitkan()->update(['target_date' => Carbon::now()->addHour()]);
        $this->assertSame(1, $this->summary()['overdue']);

        $this->terbitkan()->update(['target_date' => Carbon::now()->subHour()]);
        $this->assertSame(2, $this->summary()['overdue']);
    }

    public function test_role_lain_tidak_boleh_membuka_dashboard_unit_maintenance(): void
    {
        foreach ([$this->officer1, $this->mcs, $this->petugas] as $orang) {
            $this->actingAs($orang, 'sanctum')->getJson('/api/v1/maintenance/summary')->assertStatus(403);
            $this->actingAs($orang, 'sanctum')->getJson('/api/v1/maintenance/queue')->assertStatus(403);
        }
    }

    /**
     * Antrean urut TERLAMA DI ATAS, kebalikan dari daftar milik MCS. Ini antrean pekerjaan:
     * yang paling lama menunggu adalah yang paling perlu dilihat, bukan yang paling baru.
     */
    public function test_antrean_menaruh_yang_paling_lama_menunggu_di_atas(): void
    {
        $baru = $this->laporanTervalidasi(['date_time' => Carbon::now()->subHours(2)->toDateTimeString()]);
        $lama = $this->laporanTervalidasi(['date_time' => Carbon::now()->subDays(9)->toDateTimeString()]);

        $data = $this->actingAs($this->unit, 'sanctum')
            ->getJson('/api/v1/maintenance/queue')->assertOk()->json('data');

        $this->assertSame([$lama->uuid, $baru->uuid], array_column($data, 'uuid'));
    }

    /**
     * Isi dropdown penunjukan regu: maintenance_officer yang aktif saja, urut nama.
     *
     * Yang dikunci bukan cuma isinya tapi juga BENTUKNYA — persis tiga kunci. Baris user utuh
     * membawa password dan remember_token, dan daftar ini dibaca layar web; sekali `->get()`
     * ditulis tanpa daftar kolom, hash password ikut keluar dan tidak ada yang menyadarinya
     * karena layarnya tetap tampil benar.
     */
    public function test_daftar_petugas_hanya_maintenance_officer_yang_aktif(): void
    {
        $this->user('JMTM003', 'maintenance_officer');

        // Yang dinonaktifkan hilang dari dropdown, bukan tampil abu-abu: menawarkannya lalu
        // menolaknya 422 saat dipilih itu dua pesan yang bertentangan untuk satu orang.
        $this->user('JMTM009', 'maintenance_officer')->update(['is_active' => false]);

        $daftar = $this->daftarPetugas();

        $this->assertSame(['Orang JMTM001', 'Orang JMTM003'], array_column($daftar, 'name'));
        $this->assertSame(['id', 'identity_code', 'name'], array_keys($daftar[0]));
    }

    /**
     * Penjaganya sama dengan endpoint Unit Maintenance lainnya — termasuk untuk
     * maintenance_officer sendiri, yang justru jadi ISI daftarnya. Dia tidak menugaskan
     * siapa pun, jadi dia tidak perlu daftarnya.
     */
    public function test_role_lain_tidak_boleh_membuka_daftar_petugas(): void
    {
        $this->getJson('/api/v1/maintenance/officers')->assertStatus(401);

        foreach ([$this->officer1, $this->mcs, $this->petugas] as $orang) {
            $this->actingAs($orang, 'sanctum')->getJson('/api/v1/maintenance/officers')->assertStatus(403);
        }
    }

    /**
     * TRIPWIRE. Dropdown dan validator harus membaca satu predikat yang sama
     * (User::petugasMaintenance()), dan test inilah yang jatuh kalau suatu saat ada yang
     * menuliskannya dua kali lalu salah satunya berubah.
     *
     * Dua arah sekaligus, karena melencengnya bisa ke dua arah:
     * daftar lebih LONGGAR  -> dropdown menawarkan orang yang ditolak 422 saat dipilih
     * daftar lebih KETAT    -> orang yang sah tidak pernah bisa ditugaskan lewat layar
     *
     * Gejalanya sama-sama muncul di layar orang lain, bukan di sini — makanya dikunci di sini.
     */
    public function test_setiap_petugas_di_daftar_diterima_sebagai_pelaksana(): void
    {
        $this->user('JMTM002', 'maintenance_officer');
        $wo = $this->terbitkan();

        $ids = array_column($this->daftarPetugas(), 'id');
        $this->assertCount(2, $ids);

        $this->actingAs($this->unit, 'sanctum')
            ->putJson("/api/v1/work-orders/{$wo->id}/team", ['assignee_ids' => $ids, 'coordinator_id' => $ids[0]])
            ->assertOk();

        User::where('identity_code', 'JMTM002')->update(['is_active' => false]);

        $this->assertSame(['JMTM001'], array_column($this->daftarPetugas(), 'identity_code'));

        $errors = $this->actingAs($this->unit, 'sanctum')
            ->putJson("/api/v1/work-orders/{$wo->id}/team", ['assignee_ids' => $ids])
            ->assertStatus(422)
            ->json('errors');

        $this->assertSame(
            ['Pelaksana harus petugas maintenance yang akunnya aktif.'],
            $errors['assignee_ids.1'],
        );
    }

    // =====================================================================
    // Pencarian & antrean untuk form penerbitan WO (3 Okt)
    // =====================================================================

    /**
     * Pencarian katalog DI SERVER — aset di halaman 3 tidak boleh "tidak ditemukan"
     * cuma karena halamannya belum termuat di browser.
     */
    public function test_katalog_aset_dicari_di_server_dan_ukuran_halamannya_dijepit(): void
    {
        AssetCatalog::create([
            'name' => 'Lampu PJU', 'asset_type_id' => $this->catalog->asset_type_id,
            'component' => 'Tiang PJU 9 m', 'unit_of_measurement' => 'bh',
        ]);
        $cari = fn (string $q) => $this->actingAs($this->unit, 'sanctum')->getJson('/api/v1/asset-catalog?'.$q)->assertOk();

        $this->assertSame(['Tiang PJU 9 m'], array_column($cari('search=pju')->json('data'), 'component'));
        $this->assertCount(1, $cari('search=4000X312')->json('data'));
        // Wildcard dari kata kunci tidak boleh ikut jadi wildcard SQL.
        $this->assertCount(0, $cari('search='.urlencode('%'))->json('data'));
        // Bukan teks → diabaikan, bukan 500.
        $this->assertCount(2, $cari('search[]=pju')->json('data'));

        $this->assertSame(1, $cari('per_page=1')->json('meta.per_page'));
        $this->assertSame(100, $cari('per_page=100000')->json('meta.per_page'));
    }

    /**
     * Antrean patroli bisa dicari: uraian, aset, dan KM. Angka dicocokkan ke KM PERSIS —
     * "14" tidak boleh ikut menjaring KM 114.
     */
    public function test_antrean_patroli_dicari_di_server_dan_km_dicocokkan_persis(): void
    {
        $patroli = fn (int $km, string $uraian) => app(PatrolReportSyncService::class)->upsert((string) Str::uuid(), $this->officer1, [
            'patrolled_at' => Carbon::now()->subHour()->toDateTimeString(),
            'route' => 'A', 'is_damaged' => true,
            'asset_catalog_id' => $this->catalog->id,
            'location_type' => 'Jalur Utama', 'km' => $km, 'damage_description' => $uraian,
        ]);
        $patroli(14, 'Tiang miring.');
        $patroli(114, 'Beam retak.');

        $cari = fn (string $q) => array_column($this->actingAs($this->unit, 'sanctum')
            ->getJson('/api/v1/maintenance/patrol-queue?search='.urlencode($q))->assertOk()->json('data'), 'km');

        $this->assertSame([14], $cari('14'));
        $this->assertSame([14], $cari('KM 14'));
        $this->assertSame([114], $cari('retak'));
        $this->assertSame([14, 114], $cari('guard rail'));
        $this->assertSame([], $cari('99999'));
    }

    /**
     * Satu baris antrean untuk form penerbitan WO yang dibuka langsung. Bentuknya sama
     * dengan baris antrean — TANPA syarat pembayaran — dan 404 begitu WO-nya terbit.
     */
    public function test_satu_baris_antrean_untuk_form_penerbitan_wo(): void
    {
        $report = $this->laporanTervalidasi(['km' => 17]);
        $url = "/api/v1/maintenance/queue/{$report->uuid}";

        $baris = $this->actingAs($this->unit, 'sanctum')->getJson($url)->assertOk()->json('data.report');
        $this->assertSame(17, $baris['km']);
        $this->assertCount(1, $baris['damaged_items']);
        $this->assertArrayNotHasKey('settlement_type', $baris);

        $this->actingAs($this->officer1, 'sanctum')->getJson($url)->assertStatus(403);

        app(WorkOrderService::class)->issue($this->unit, [
            'trigger_source' => 'Insiden', 'incident_report_id' => $report->uuid,
            'priority' => 'Tinggi', 'target_date' => Carbon::tomorrow()->toDateString(),
            'assignee_ids' => [$this->petugas->id],
        ]);
        $this->actingAs($this->unit, 'sanctum')->getJson($url)->assertNotFound();
    }

    // =====================================================================
    // Bantuan
    // =====================================================================

    private function user(string $code, string $role = 'field_officer'): User
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

    /** @return array<int, array<string, mixed>> */
    private function daftarPetugas(): array
    {
        return $this->actingAs($this->unit, 'sanctum')
            ->getJson('/api/v1/maintenance/officers')->assertOk()->json('data.officers');
    }

    private function summary(): array
    {
        return $this->actingAs($this->unit, 'sanctum')
            ->getJson('/api/v1/maintenance/summary')->assertOk()->json('data');
    }

    /** @return array<int, array<string, mixed>> */
    private function items(int $jumlah = 1): array
    {
        return array_fill(0, $jumlah, [
            'asset_catalog_id' => $this->price->asset_catalog_id,
            'catalog_price_id' => $this->price->id,
            'quantity' => 2,
            'damage_percentage' => 100,
        ]);
    }

    private function laporan(array $override = []): IncidentReport
    {
        return app(IncidentReportSyncService::class)->upsert(
            (string) Str::uuid(),
            $this->officer1,
            $override + [
                'date_time' => Carbon::now()->subDay()->setTime(14, 20)->toDateTimeString(),
                'km' => 12, 'm' => 300, 'route' => 'A', 'lane' => 'Lajur 1',
                'causer' => 'Pecah Ban',
                'analyze' => 'Rusak Berat',
                'is_identified' => false,
                'officer_2_id' => $this->officer2->id,
                'damaged_items' => $this->items(),
            ],
        );
    }

    /** Redesain 16 Sep: penanda tangan sudah ditentukan petugas saat submit. */
    private function sahkanBaKejadian(IncidentReport $report): void
    {
        app(IncidentVerificationService::class)->validateIncidentBa($report, $this->mcs, []);
    }

    private function laporanTervalidasi(array $override = []): IncidentReport
    {
        $report = $this->laporan($override);
        $this->sahkanBaKejadian($report);
        app(IncidentVerificationService::class)->validateDamageBa($report, $this->mcs);

        return $report;
    }

    /**
     * WO manual, supaya angka kartu WO tidak ikut menghabiskan antrean — yang diuji di
     * sini status pekerjaannya, bukan asal-usulnya.
     */
    private function terbitkan(): WorkOrder
    {
        return app(WorkOrderService::class)->issue($this->unit, [
            'trigger_source' => 'Temuan Patroli',
            'asset_catalog_id' => $this->catalog->id,
            'damage_description' => 'Lampu PJU KM 45+200 mati.',
            'location_type' => 'Jalur Utama',
            'km' => 45, 'm' => 200, 'route' => 'B', 'lane' => 'Bahu Jalan',
            'priority' => 'Sedang',
            'target_date' => Carbon::tomorrow()->toDateString(),
            'assignee_ids' => [$this->petugas->id],
        ]);
    }
}
