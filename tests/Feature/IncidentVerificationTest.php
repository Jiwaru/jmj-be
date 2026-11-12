<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AssetCatalog;
use App\Models\AssetType;
use App\Models\CatalogPrice;
use App\Models\DamageBa;
use App\Models\IncidentBa;
use App\Models\IncidentReport;
use App\Models\User;
use App\Services\Shared\IncidentReportSyncService;
use App\Services\Shared\NumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Fase 2 — MCS Supervisor.
 *
 * Yang paling banyak dijaga di sini bukan jalur normalnya, tapi konsekuensi dari satu
 * keputusan: PENGESAHAN DIPISAH PER BA. Kalau suatu saat digabung lagi jadi satu
 * aksi, separuh berkas ini merah — dan itu memang tujuannya.
 */
class IncidentVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $officer1;

    private User $officer2;

    private User $mcs;

    private CatalogPrice $price;

    protected function setUp(): void
    {
        parent::setUp();

        $this->officer1 = $this->user('JMTO001');
        $this->officer2 = $this->user('JMTO002');
        $this->mcs = $this->user('JMTO003', 'mcs_supervisor');

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
            'changed_by' => $this->officer1->id,
        ]);

        Storage::fake('local');
    }

    private function user(string $code, string $role = 'field_officer'): User
    {
        return User::create([
            'identity_code' => $code,
            'name' => "Orang {$code}",
            'position' => 'Petugas Patroli',
            'entity' => 'jmto',
            'role' => $role,
            'is_active' => true,
            'password' => 'rahasia',
        ]);
    }

    /** Laporan yang sudah mendarat di server dan mengantre di meja MCS. */
    private function report(array $override = []): IncidentReport
    {
        return app(IncidentReportSyncService::class)->upsert(
            (string) Str::uuid(),
            $this->officer1,
            $override + [
                'date_time' => '2026-08-21 14:20:00',
                'km' => 12, 'm' => 300, 'route' => 'A', 'lane' => 'Lajur 1',
                'causer' => 'Pecah Ban',
                'analyze' => 'Rusak Berat',
                'is_identified' => false,
                'officer_2_id' => $this->officer2->id,
                'damaged_items' => [[
                    'asset_catalog_id' => $this->price->asset_catalog_id,
                    'catalog_price_id' => $this->price->id,
                    'quantity' => 2,
                    'damage_percentage' => 100,
                ]],
            ],
        );
    }

    private function asMcs(): self
    {
        $this->actingAs($this->mcs, 'sanctum');

        return $this;
    }

    /**
     * Redesain 16 Sep: MCS Sahkan tanpa mengetik nama penanda tangan — keduanya
     * sudah ditentukan petugas saat submit (lihat `officer_2_id` di `report()`).
     * Body-nya boleh kosong; `duty_period` opsional.
     */
    private function sahkanBak(IncidentReport $report): TestResponse
    {
        return $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/incident-ba/validate");
    }

    // ---- Urutan dua BA ---------------------------------------------------------

    public function test_sahkan_ba_kejadian_menerbitkan_nomor_tapi_laporan_belum_verified(): void
    {
        $report = $this->report();

        $this->sahkanBak($report)->assertOk();

        $ba = IncidentBa::where('incident_report_id', $report->uuid)->sole();
        $this->assertSame('validated', $ba->validation_status);
        $this->assertMatchesRegularExpression('#^\d{3}/TM\.JOGLO/[IVX]+/\d{4}$#', $ba->incident_ba_number);

        $report->refresh();
        $this->assertNotNull($report->report_number);
        // Masih mengantre: BA Kerusakan belum diputuskan. Inilah yang membedakan
        // model dua-BA dari satu-aksi — dengan satu aksi, di sini sudah 'verified'.
        $this->assertSame('pending_verification', $report->report_status);
    }

    public function test_ba_kerusakan_ditolak_kalau_ba_kejadian_belum_disahkan(): void
    {
        $report = $this->report();

        $this->asMcs()
            ->postJson("/api/v1/incident-reports/{$report->uuid}/damage-ba/validate")
            ->assertStatus(422);

        $this->assertSame(0, DamageBa::count());
    }

    public function test_sahkan_keduanya_berurutan_menutup_laporan_jadi_verified(): void
    {
        $report = $this->report();

        $this->sahkanBak($report)->assertOk();
        $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/damage-ba/validate")->assertOk();

        $this->assertSame('verified', $report->refresh()->report_status);
        $this->assertSame('validated', DamageBa::sole()->validation_status);
    }

    public function test_laporan_tanpa_kerusakan_langsung_closed_dan_tidak_punya_ba_kerusakan(): void
    {
        $report = $this->report(['analyze' => 'Tidak Ada Kerusakan Aset', 'damaged_items' => []]);

        $this->sahkanBak($report)->assertOk();

        $this->assertSame('closed', $report->refresh()->report_status);
        $this->assertSame(0, DamageBa::count());

        // Dan BA Kerusakan tidak bisa dipaksakan terbit untuknya.
        $this->asMcs()
            ->postJson("/api/v1/incident-reports/{$report->uuid}/damage-ba/validate")
            ->assertStatus(422);
    }

    public function test_ba_kejadian_tidak_bisa_disahkan_dua_kali(): void
    {
        $report = $this->report();

        $this->sahkanBak($report)->assertOk();
        $this->sahkanBak($report)->assertStatus(422);
    }

    /**
     * Tabrak lari: aset rusak, pelakunya tidak ada, dan BA Kerusakan TETAP terbit.
     *
     * Ini yang mengunci temuan paling berbahaya di dokumen — `Konsep Aplikasi` baris
     * 110 menulis BA Kerusakan cuma untuk pengguna jalan yang teridentifikasi. Kalau
     * itu diikuti, tabrak lari tidak pernah punya damage_ba, tidak pernah masuk
     * antrean Work Order, dan asetnya rusak selamanya. Alur Fase 3 yang benar.
     */
    public function test_tabrak_lari_tetap_dapat_ba_kerusakan(): void
    {
        $report = $this->report(['is_identified' => false, 'culprits' => []]);

        $this->sahkanBak($report)->assertOk();
        $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/damage-ba/validate")->assertOk();

        $this->assertFalse($report->refresh()->is_identified);
        $this->assertSame('validated', DamageBa::sole()->validation_status);
    }

    public function test_terbilang_memakai_ejaan_indonesia(): void
    {
        // 2 x 1.976.600 = 3.953.200
        $report = $this->report();

        $this->sahkanBak($report);
        $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/damage-ba/validate");

        $this->assertSame(
            'tiga juta sembilan ratus lima puluh tiga ribu dua ratus rupiah',
            DamageBa::sole()->amount_in_words,
        );
    }

    // ---- Tiga counter terpisah -------------------------------------------------

    /**
     * Nomor BA Kerusakan tertinggal di belakang nomor BA Kejadian, karena laporan
     * tanpa kerusakan tidak pernah menerbitkannya. Kalau ketiganya berbagi satu
     * counter, angka-angka ini akan sama dan test ini merah.
     */
    public function test_tiga_counter_nomor_berjalan_sendiri_sendiri(): void
    {
        $tanpaKerusakan = $this->report(['analyze' => 'Tidak Ada Kerusakan Aset', 'damaged_items' => []]);
        $this->sahkanBak($tanpaKerusakan);

        $denganKerusakan = $this->report();
        $this->sahkanBak($denganKerusakan);
        $this->asMcs()->postJson("/api/v1/incident-reports/{$denganKerusakan->uuid}/damage-ba/validate");

        $urut = fn (?string $nomor) => (int) explode('/', (string) $nomor)[0];

        $this->assertSame(2, $urut(IncidentBa::where('incident_report_id', $denganKerusakan->uuid)->sole()->incident_ba_number));
        $this->assertSame(1, $urut(DamageBa::sole()->damage_ba_number));
        $this->assertSame(2, $urut($denganKerusakan->refresh()->report_number));
    }

    public function test_nomor_urut_reset_per_tahun(): void
    {
        $this->sahkanBak($this->report());

        $urut = fn (string $nomor) => (int) explode('/', $nomor)[0];

        DB::transaction(function () use ($urut) {
            $generator = app(NumberGenerator::class);

            // Tahun berjalan sudah punya 001 dari pengesahan di atas.
            $this->assertSame(2, $urut($generator->next('incident_ba')));
            // Tahun depan mulai dari 001 lagi - kunci advisory dan filter LIKE
            // sama-sama membawa tahunnya.
            $this->assertSame(1, $urut($generator->next('incident_ba', Carbon::now()->addYear())));
        });
    }

    // ---- Tolak, perbaiki, sahkan ----------------------------------------------

    public function test_tolak_ba_kejadian_memberi_nomor_ba_tapi_bukan_nomor_laporan(): void
    {
        $report = $this->report();

        $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/incident-ba/reject",
            ['rejection_reason' => 'Foto TKP tidak menunjukkan plat nomor.'])
            ->assertOk();

        $ba = IncidentBa::sole();
        $this->assertSame('rejected', $ba->validation_status);
        $this->assertNotNull($ba->incident_ba_number, 'kolomnya NOT NULL — BA yang ditolak tetap bernomor');
        $this->assertSame('Foto TKP tidak menunjukkan plat nomor.', $ba->rejection_reason);

        $report->refresh();
        $this->assertSame('rejected', $report->report_status);
        // Nomor laporan penanda perkara yang sudah DIAKUI. Yang ditolak belum.
        $this->assertNull($report->report_number);
    }

    public function test_ba_yang_ditolak_lalu_disahkan_memakai_nomor_yang_sama_dan_tetap_satu_baris(): void
    {
        $report = $this->report();

        $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/incident-ba/reject",
            ['rejection_reason' => 'Foto TKP tidak terbaca.']);

        $nomorLama = IncidentBa::sole()->incident_ba_number;

        // Petugas memperbaiki dan mengirim ulang — laporannya kembali mengantre.
        $this->report(['km' => 13]);
        app(IncidentReportSyncService::class)->upsert($report->uuid, $this->officer1, [
            'date_time' => '2026-08-21 14:20:00',
            'km' => 12, 'm' => 300, 'route' => 'A', 'lane' => 'Lajur 1',
            'causer' => 'Pecah Ban', 'analyze' => 'Rusak Berat', 'is_identified' => false,
            'officer_2_id' => $this->officer2->id,
            'damaged_items' => [[
                'asset_catalog_id' => $this->price->asset_catalog_id,
                'catalog_price_id' => $this->price->id,
                'quantity' => 2, 'damage_percentage' => 100,
            ]],
        ]);

        $this->sahkanBak($report->refresh())->assertOk();

        $ba = IncidentBa::where('incident_report_id', $report->uuid)->get();
        $this->assertCount(1, $ba, 'incident_report_id unique — tidak boleh lahir BA kedua');
        $this->assertSame($nomorLama, $ba->first()->incident_ba_number);
        $this->assertSame('validated', $ba->first()->validation_status);
        $this->assertNull($ba->first()->rejection_reason, 'alasan penolakan lama tidak boleh ikut tercetak');
        $this->assertNotNull($report->refresh()->report_number, 'nomor laporan baru terbit sekarang');
    }

    /**
     * Kombinasi yang MUSTAHIL ada di model satu-aksi: laporan ditolak, tapi BA
     * Kejadian-nya sudah disahkan dan bernomor. Kronologinya memang tidak salah —
     * yang salah kalkulasinya.
     */
    public function test_tolak_ba_kerusakan_tidak_membatalkan_ba_kejadian_yang_sudah_sah(): void
    {
        $report = $this->report();

        $this->sahkanBak($report)->assertOk();
        $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/damage-ba/reject",
            ['rejection_reason' => 'Volume guardrail tidak cocok dengan foto TKP.'])
            ->assertOk();

        $this->assertSame('rejected', $report->refresh()->report_status);
        $this->assertSame('validated', IncidentBa::sole()->validation_status);
        $this->assertNotNull($report->report_number);
        $this->assertSame('rejected', DamageBa::sole()->validation_status);
    }

    // Aturan "dua penanda tangan tidak boleh orang yang sama" dan "harus petugas
    // lapangan" pindah ke IncidentSyncTest (redesain 16 Sep) — itu sekarang aturan
    // `officer_2_id` di endpoint sync, bukan di endpoint validate ini.

    /**
     * Bentuk response pengesahan itu DISENGAJA, bukan kebetulan.
     *
     * Sebelumnya BaPdfService memuat relasi ke objek yang sama dengan yang
     * dikembalikan controller, jadi response ikut membawa officer1, officer2,
     * validator, dan seluruh baris laporan — cuma karena template PDF membutuhkannya.
     * Tambah satu relasi di template, response endpoint ini ikut membengkak.
     */
    public function test_response_pengesahan_tidak_ikut_membawa_relasi_yang_dibutuhkan_pdf(): void
    {
        $report = $this->report();

        $data = $this->sahkanBak($report)->assertOk()->json('data');

        $this->assertSame(
            ['uuid', 'report_number', 'report_status', 'is_asset_broken'],
            array_keys($data['report']),
        );

        foreach (['officer1', 'officer2', 'validator', 'incident_report'] as $relasi) {
            $this->assertArrayNotHasKey($relasi, $data['incident_ba'],
                "relasi '{$relasi}' bocor ke response gara-gara kebutuhan template PDF");
        }
    }

    // ---- Koreksi ---------------------------------------------------------------

    public function test_koreksi_menghitung_ulang_uang_dan_mencatat_jejaknya(): void
    {
        $report = $this->report();
        $item = $report->damagedItems()->sole();

        $this->asMcs()->patchJson("/api/v1/incident-reports/{$report->uuid}/correction", [
            'km' => 21,
            'items' => [['id' => $item->id, 'quantity' => 3]],
        ])->assertOk();

        $report->refresh();
        $this->assertSame(21, $report->km);
        // 3 x 1.976.600 = 5.929.800
        $this->assertSame('5929800.00', $report->total_estimation);
        $this->assertSame('5929800.00', $item->refresh()->subtotal);

        $log = ActivityLog::sole();
        $this->assertSame('correction', $log->action);
        $this->assertSame('incident_report', $log->auditable_type);
        $this->assertSame($report->uuid, $log->auditable_id);
        $this->assertSame(12, $log->old_values['km']);
        $this->assertSame(21, $log->new_values['km']);
        $this->assertSame(3, (int) $log->new_values["items.{$item->id}.quantity"]);
    }

    public function test_koreksi_ditolak_setelah_laporan_disahkan(): void
    {
        $report = $this->report(['analyze' => 'Tidak Ada Kerusakan Aset', 'damaged_items' => []]);
        $this->sahkanBak($report);

        $this->asMcs()->patchJson("/api/v1/incident-reports/{$report->uuid}/correction", ['km' => 21])
            ->assertStatus(422);
    }

    public function test_koreksi_menolak_baris_kerusakan_milik_laporan_lain(): void
    {
        $report = $this->report();
        $lain = $this->report();
        $itemOrangLain = $lain->damagedItems()->sole();

        $this->asMcs()->patchJson("/api/v1/incident-reports/{$report->uuid}/correction", [
            'items' => [['id' => $itemOrangLain->id, 'quantity' => 99]],
        ])->assertStatus(422);

        $this->assertSame('2.00', $itemOrangLain->refresh()->quantity);
    }

    public function test_koreksi_tidak_bisa_menyentuh_analyze(): void
    {
        $report = $this->report();

        $this->asMcs()->patchJson("/api/v1/incident-reports/{$report->uuid}/correction", [
            'km' => 21,
            'analyze' => 'Tidak Ada Kerusakan Aset',
        ])->assertOk();

        $report->refresh();
        $this->assertSame('Rusak Berat', $report->analyze, 'analyze bukan koreksi ketik — itu penilaian di TKP');
        $this->assertTrue($report->is_asset_broken);
    }

    // ---- Laporan terkunci begitu BA Kejadian dibekukan --------------------------

    /**
     * Laporan yang ada kerusakan asetnya TETAP `pending_verification` setelah BA
     * Kejadian disahkan — dia masih menunggu BA Kerusakan. Selama yang dijaga cuma
     * status, petugas bisa menulis ulang laporan setelah BA-nya beku di disk, dan
     * BA Kerusakan yang terbit belakangan menyebut lokasi yang berbeda dari BA
     * Kejadian untuk satu perkara yang sama.
     */
    public function test_petugas_tidak_bisa_kirim_ulang_setelah_ba_kejadian_disahkan(): void
    {
        $report = $this->report();
        $this->sahkanBak($report)->assertOk();
        $this->assertSame('pending_verification', $report->refresh()->report_status);

        $this->actingAs($this->officer1, 'sanctum')
            ->putJson("/api/v1/incident-reports/{$report->uuid}", [
                'date_time' => '2026-08-21 14:20:00', 'km' => 99, 'route' => 'A',
                'causer' => 'Pecah Ban', 'analyze' => 'Rusak Berat', 'is_identified' => false,
                'officer_2_id' => $this->officer2->id,
                // Payload-nya sah seluruhnya — yang menolaknya HARUS penjaga status,
                // bukan validasi yang kebetulan lebih dulu jalan.
                'damaged_items' => [[
                    'asset_catalog_id' => $this->price->asset_catalog_id,
                    'catalog_price_id' => $this->price->id,
                    'quantity' => 2, 'damage_percentage' => 100,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['report_status']]);

        $this->assertSame(12, $report->refresh()->km);
    }

    /**
     * Kunci di atas TIDAK BOLEH menutup jalur rework: BA Kerusakan yang ditolak
     * dikembalikan ke petugas justru supaya daftar kerusakannya dibetulkan, dan di
     * situ BA Kejadian memang sudah `validated`. Menutupnya juga berarti laporan yang
     * BA Kerusakannya ditolak tidak pernah bisa diperbaiki siapa pun.
     */
    public function test_kiriman_ulang_kembali_dibuka_setelah_ba_kerusakan_ditolak(): void
    {
        $report = $this->report();
        $this->sahkanBak($report)->assertOk();
        $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/damage-ba/reject", [
            'rejection_reason' => 'Volume guardrail kelebihan satu batang.',
        ])->assertOk();

        $this->actingAs($this->officer1, 'sanctum')
            ->putJson("/api/v1/incident-reports/{$report->uuid}", [
                'date_time' => '2026-08-21 14:20:00', 'km' => 12, 'm' => 300,
                'route' => 'A', 'lane' => 'Lajur 1',
                'causer' => 'Pecah Ban', 'analyze' => 'Rusak Berat', 'is_identified' => false,
                'officer_2_id' => $this->officer2->id,
                'damaged_items' => [[
                    'asset_catalog_id' => $this->price->asset_catalog_id,
                    'catalog_price_id' => $this->price->id,
                    'quantity' => 1, 'damage_percentage' => 100,
                ]],
            ])
            ->assertOk();

        $this->assertSame('pending_verification', $report->refresh()->report_status);
        $this->assertSame('1976600.00', $report->total_estimation);
    }

    /**
     * Kunci yang sama menutup jalan buntu: `analyze` yang dibalik jadi "Tidak Ada
     * Kerusakan Aset" setelah BA Kejadian sah membuat KEEMPAT aksi MCS menolak
     * laporan itu, dan laporannya hilang dari kedua antrean sambil tetap
     * `pending_verification`.
     */
    public function test_analyze_tidak_bisa_dibalik_setelah_ba_kejadian_disahkan(): void
    {
        $report = $this->report();
        $this->sahkanBak($report)->assertOk();

        $this->actingAs($this->officer1, 'sanctum')
            ->putJson("/api/v1/incident-reports/{$report->uuid}", [
                'date_time' => '2026-08-21 14:20:00', 'km' => 12, 'route' => 'A',
                'causer' => 'Pecah Ban', 'analyze' => 'Tidak Ada Kerusakan Aset', 'is_identified' => false,
                'officer_2_id' => $this->officer2->id,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['report_status']]);

        $this->assertTrue($report->refresh()->is_asset_broken);
        // Masih bisa diselesaikan: BA Kerusakan tetap punya jalan terbit.
        $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/damage-ba/validate")->assertOk();
    }

    /**
     * Lokasi tercetak di BA Kejadian, dan yang sudah disahkan beku di disk. Koreksi
     * setelah itu tidak membetulkan berkas yang beku, tapi BA Kerusakan membacanya
     * dari database — jadi dua dokumen resmi untuk satu perkara menyebut KM berbeda.
     */
    public function test_koreksi_lokasi_ditolak_setelah_ba_kejadian_disahkan(): void
    {
        $report = $this->report();
        $this->sahkanBak($report)->assertOk();

        $this->asMcs()->patchJson("/api/v1/incident-reports/{$report->uuid}/correction", ['km' => 21])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['km']]);

        $this->assertSame(12, $report->refresh()->km);
    }

    /**
     * Volume TETAP boleh dikoreksi setelah BA Kejadian sah — yang mencetaknya cuma
     * BA Kerusakan, dan BA Kerusakan belum terbit. Kalau ini ikut tertutup, salah
     * ketik jumlah batang guardrail memaksa laporan berputar balik ke petugas yang
     * mungkin sedang off-shift.
     */
    public function test_koreksi_volume_masih_boleh_setelah_ba_kejadian_disahkan(): void
    {
        $report = $this->report();
        $this->sahkanBak($report)->assertOk();
        $item = $report->damagedItems()->sole();

        $this->asMcs()->patchJson("/api/v1/incident-reports/{$report->uuid}/correction", [
            'items' => [['id' => $item->id, 'quantity' => 3]],
        ])->assertOk();

        $this->assertSame('5929800.00', $report->refresh()->total_estimation);
    }

    /**
     * MCS yang salah menekan Tolak boleh langsung mengesahkan — tanpa menunggu petugas
     * mengirim ulang laporan yang sama dari HP yang mungkin sedang off-shift.
     * Nomor BA-nya dipertahankan dan alasan penolakannya dibersihkan.
     */
    public function test_mcs_boleh_membatalkan_penolakannya_dengan_langsung_mengesahkan(): void
    {
        $report = $this->report();
        $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/incident-ba/reject",
            ['rejection_reason' => 'Salah pencet, laporannya sebenarnya benar.'])->assertOk();

        $nomor = $report->refresh()->incidentBa->incident_ba_number;
        $this->assertSame('rejected', $report->report_status);

        $this->sahkanBak($report)->assertOk();

        $report->refresh();
        $this->assertSame('validated', $report->incidentBa->validation_status);
        $this->assertSame($nomor, $report->incidentBa->incident_ba_number);
        $this->assertNull($report->incidentBa->rejection_reason);
        $this->assertSame(1, $report->incidentBa()->count());
    }

    /** Koreksi TETAP tertutup untuk yang ditolak — laporannya sedang di tangan petugas. */
    public function test_koreksi_tetap_ditolak_untuk_laporan_yang_ditolak(): void
    {
        $report = $this->report();
        $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/incident-ba/reject",
            ['rejection_reason' => 'Foto TKP tidak menunjukkan plat nomor.'])->assertOk();

        $this->asMcs()->patchJson("/api/v1/incident-reports/{$report->uuid}/correction", ['km' => 21])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['report_status']]);
    }

    /**
     * Shift DITURUNKAN dari jam kejadian, tidak diterima dari MCS. Kalau suatu saat
     * kolomnya kembali diisi kiriman klien, test ini yang merah duluan.
     */
    public function test_shift_diturunkan_dari_jam_kejadian_bukan_dari_kiriman_mcs(): void
    {
        // 14:20 jatuh di Shift 2 (14-22), dan MCS mengirim '1' — yang menang jamnya.
        $siang = $this->report(['date_time' => '2026-08-21 14:20:00']);
        $this->asMcs()->postJson("/api/v1/incident-reports/{$siang->uuid}/incident-ba/validate",
            ['shift' => '1'])->assertOk();
        $this->assertSame('2', $siang->refresh()->incidentBa->shift);

        // 23:40 jatuh di Shift 3, yang rentangnya melewati tengah malam.
        $malam = $this->report(['date_time' => '2026-08-21 23:40:00']);
        $this->sahkanBak($malam)->assertOk();
        $this->assertSame('3', $malam->refresh()->incidentBa->shift);

        // 03:15 masih Shift 3 — sisi lain dari rentang yang sama.
        $dini = $this->report(['date_time' => '2026-08-22 03:15:00']);
        $this->sahkanBak($dini)->assertOk();
        $this->assertSame('3', $dini->refresh()->incidentBa->shift);

        // BA Kerusakan menyalinnya dari BA Kejadian, bukan menghitung sendiri.
        $this->asMcs()->postJson("/api/v1/incident-reports/{$dini->uuid}/damage-ba/validate")->assertOk();
        $this->assertSame('3', $dini->refresh()->incidentBa->damageBa->shift);
    }

    // ---- Kolom baru ------------------------------------------------------------

    public function test_party_role_dan_location_type_tersimpan_dari_kiriman_hp(): void
    {
        $report = $this->report([
            'location_type' => 'Ramp Keluar',
            'is_identified' => true,
            'culprits' => [['full_name' => 'Rian Hidayat', 'party_role' => 'Pengemudi']],
        ]);

        $this->assertSame('Ramp Keluar', $report->refresh()->location_type);
        $this->assertSame('Pengemudi', $report->culprits()->sole()->party_role);
    }

    public function test_location_type_default_mainline_kalau_hp_tidak_mengirimnya(): void
    {
        $this->assertSame('Jalur Utama', $this->report()->refresh()->location_type);
    }

    public function test_party_role_di_luar_daftar_ditolak(): void
    {
        $this->actingAs($this->officer1, 'sanctum')
            ->putJson('/api/v1/incident-reports/'.Str::uuid(), [
                'date_time' => '2026-08-21 14:20:00',
                'causer' => 'Pecah Ban', 'analyze' => 'Rusak Berat', 'is_identified' => true,
                'culprits' => [['full_name' => 'Rian', 'party_role' => 'Penumpang']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('culprits.0.party_role');
    }

    // ---- Batas akses -----------------------------------------------------------

    public function test_petugas_lapangan_ditolak_di_semua_endpoint_mcs(): void
    {
        $report = $this->report();
        $this->actingAs($this->officer1, 'sanctum');

        foreach (['incident-ba/validate', 'incident-ba/reject', 'damage-ba/validate', 'damage-ba/reject', 'escalation-link'] as $aksi) {
            $this->postJson("/api/v1/incident-reports/{$report->uuid}/{$aksi}")->assertStatus(403);
        }

        $this->patchJson("/api/v1/incident-reports/{$report->uuid}/correction", ['km' => 21])->assertStatus(403);
        $this->getJson('/api/v1/mcs/summary')->assertStatus(403);
    }

    public function test_mcs_boleh_membuka_laporan_petugas_lain(): void
    {
        $report = $this->report();

        $this->asMcs()->getJson("/api/v1/incident-reports/{$report->uuid}")
            ->assertOk()
            ->assertJsonPath('data.report.uuid', $report->uuid);
    }

    /** Kartu Otorisasi BA menampilkan nama pengesah, bukan cuma validated_by. */
    public function test_detail_laporan_membawa_nama_pengesah_kedua_ba(): void
    {
        $report = $this->report();
        $this->sahkanBak($report)->assertOk();
        $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/damage-ba/validate")->assertOk();

        $ba = $this->asMcs()->getJson("/api/v1/incident-reports/{$report->uuid}")
            ->assertOk()
            ->json('data.report.incident_ba');

        $this->assertSame($this->mcs->name, $ba['validator']['name']);
        $this->assertSame($this->mcs->name, $ba['damage_ba']['validator']['name']);
        $this->assertArrayNotHasKey('password', $ba['validator']);
    }

    // ---- Eskalasi keringanan ---------------------------------------------------

    private function laporanKeringanan(): IncidentReport
    {
        $report = $this->report([
            'is_identified' => true,
            'settlement_type' => 'Tunai / Transfer',
            'relief_requested' => true,
            'culprits' => [[
                'full_name' => 'Rian Hidayat',
                'party_role' => 'Pengemudi',
                'national_id' => '3271902805920001',
                'driver_license_number' => '3471010101010001',
            ]],
        ]);

        $this->sahkanBak($report);
        $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/damage-ba/validate");

        return $report->refresh();
    }

    /**
     * Keringanan itu keringanan ATAS SUATU NILAI. Laporan tanpa kerusakan aset tetap
     * bisa jadi `closed` lewat BA Kejadian saja (Alur 2.1), dan tanpa penjaga ini
     * tombolnya menyusun "BA Kerusakan -, Nilai Rp 0" ke WhatsApp Direksi.
     */
    public function test_eskalasi_ditolak_kalau_belum_ada_ba_kerusakan_yang_sah(): void
    {
        $report = $this->report([
            'analyze' => 'Tidak Ada Kerusakan Aset',
            'conclusion' => 'Selesai',
            'damaged_items' => [],
            'is_identified' => true,
            'settlement_type' => 'Tunai / Transfer',
            'relief_requested' => true,
            'culprits' => [['full_name' => 'Rian Hidayat', 'party_role' => 'Pengemudi']],
        ]);
        $this->sahkanBak($report)->assertOk();
        $this->assertSame('closed', $report->refresh()->report_status);

        $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/escalation-link")
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['damage_ba']]);
    }

    public function test_eskalasi_ditolak_kalau_tidak_mengajukan_keringanan(): void
    {
        $report = $this->report();
        $this->sahkanBak($report);
        $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/damage-ba/validate");

        $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/escalation-link")
            ->assertStatus(422);
    }

    private function pesanEskalasi(IncidentReport $report): string
    {
        $url = $this->asMcs()
            ->postJson("/api/v1/incident-reports/{$report->uuid}/escalation-link")
            ->assertOk()
            ->json('data.wa_url');

        // Tanpa nomor: WhatsApp membuka layar pilih kontak, MCS yang memilih tujuannya.
        $this->assertStringStartsWith('https://wa.me/?text=', $url);

        return rawurldecode(substr($url, strlen('https://wa.me/?text=')));
    }

    /**
     * Isi pesan WA dibatasi sengaja. Begitu masuk grup WhatsApp, data pribadi keluar
     * dari kendali sistem dan tidak bisa ditarik lagi.
     */
    public function test_pesan_eskalasi_tidak_membawa_data_pribadi(): void
    {
        $report = $this->laporanKeringanan();
        $report->update(['pledged_amount' => 500000]);

        $pesan = $this->pesanEskalasi($report);

        $this->assertStringContainsString('*BA Kerusakan:* '.DamageBa::sole()->damage_ba_number, $pesan);
        $this->assertStringContainsString('KM 12+300', $pesan);
        $this->assertStringContainsString('*Disanggupi:* Rp 500.000', $pesan);
        $this->assertStringContainsString('*Surat permohonan:* belum diterima', $pesan);
        $this->assertStringContainsString('*Tenggat surat:*', $pesan);

        $this->assertStringNotContainsString('3271902805920001', $pesan, 'NIK tidak boleh ikut');
        $this->assertStringNotContainsString('3471010101010001', $pesan, 'nomor SIM tidak boleh ikut');
        $this->assertStringNotContainsString('Rian Hidayat', $pesan, 'nama pelaku tidak boleh ikut');
    }

    public function test_pesan_eskalasi_tanpa_tenggat_kalau_surat_sudah_masuk(): void
    {
        $report = $this->laporanKeringanan();

        $this->asMcs()->post("/api/v1/incident-reports/{$report->uuid}/relief-letter", [
            'file_uuid' => (string) Str::uuid(),
            'file' => UploadedFile::fake()->create('surat-keringanan.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $pesan = $this->pesanEskalasi($report);

        $this->assertStringContainsString('*Surat permohonan:* sudah diterima', $pesan);
        $this->assertStringNotContainsString('Tenggat', $pesan);
        // pledged_amount kosong: persen dan selisih tidak dikarang.
        $this->assertStringContainsString('*Disanggupi:* -', $pesan);
        $this->assertStringNotContainsString('Selisih', $pesan);
    }

    public function test_eskalasi_meninggalkan_jejak_di_activity_logs(): void
    {
        $report = $this->laporanKeringanan();

        $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/escalation-link")->assertOk();

        // Satu-satunya jejak bahwa eskalasi pernah dilakukan: wa.me cuma MEMBUKA
        // WhatsApp, sistem tidak akan pernah tahu pesannya terkirim.
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'escalation_forwarded',
            'auditable_type' => 'incident_report',
            'auditable_id' => $report->uuid,
            'user_id' => $this->mcs->id,
        ]);
    }

    // ---- Notifikasi lonceng ----------------------------------------------------

    /**
     * Penolakan sampai ke pembuat laporan (bukan petugas kedua), perbaikannya sampai ke
     * MCS sekali saja — kiriman ulang karena sinyal putus tidak berbunyi lagi — dan
     * kedua pengesahan sampai ke pembuat laporan.
     */
    public function test_notifikasi_penolakan_kirim_ulang_dan_pengesahan(): void
    {
        $report = $this->report();
        $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/incident-ba/reject", [
            'rejection_reason' => 'Kronologi kurang jelas.',
        ])->assertOk();

        $this->assertSame(['incident_ba_rejected'], $this->events($this->officer1));
        $this->assertSame([], $this->events($this->officer2));
        $this->assertSame(
            'BA Kejadian laporan KM 12+300 arah A ditolak: Kronologi kurang jelas. Perbaiki lalu kirim ulang.',
            $this->officer1->notifications()->first()->data['message'],
        );

        $kirimUlang = fn () => app(IncidentReportSyncService::class)->upsert($report->uuid, $this->officer1, [
            'date_time' => '2026-08-21 14:20:00',
            'km' => 12, 'm' => 300, 'route' => 'A', 'lane' => 'Lajur 1',
            'causer' => 'Pecah Ban', 'analyze' => 'Rusak Berat', 'is_identified' => false,
            'officer_2_id' => $this->officer2->id,
            'damaged_items' => [[
                'asset_catalog_id' => $this->price->asset_catalog_id,
                'catalog_price_id' => $this->price->id,
                'quantity' => 2, 'damage_percentage' => 100,
            ]],
        ]);
        $kirimUlang();
        $kirimUlang();

        $this->assertSame(['resubmitted'], $this->events($this->mcs));

        $this->sahkanBak($report)->assertOk();
        $this->asMcs()->postJson("/api/v1/incident-reports/{$report->uuid}/damage-ba/validate")->assertOk();

        $this->assertEqualsCanonicalizing(
            ['damage_ba_validated', 'incident_ba_validated', 'incident_ba_rejected'],
            $this->events($this->officer1),
        );
        $this->assertSame($report->uuid, $this->officer1->notifications()->first()->data['incident_report_id']);
    }

    private function events(User $user): array
    {
        return $user->notifications()->get()->pluck('data.event')->all();
    }
}
