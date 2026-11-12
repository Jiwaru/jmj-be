<?php

namespace Tests\Feature;

use App\Models\AssetCatalog;
use App\Models\AssetType;
use App\Models\CatalogPrice;
use App\Models\Document;
use App\Models\IncidentReport;
use App\Models\User;
use App\Services\Shared\IncidentReportSyncService;
use App\Services\Mcs\IncidentVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Empat kartu angka di Dashboard MCS Supervisor.
 */
class McsDashboardTest extends TestCase
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

    /** Redesain 16 Sep: penanda tangan sudah ditentukan petugas saat submit. */
    private function sahkanBak(IncidentReport $report): void
    {
        app(IncidentVerificationService::class)->validateIncidentBa($report, $this->mcs, []);
    }

    private function summary(): array
    {
        return $this->actingAs($this->mcs, 'sanctum')
            ->getJson('/api/v1/mcs/summary')
            ->assertOk()
            ->json('data');
    }

    /**
     * Dua antrean yang berbeda angkanya — dan HANYA bisa berbeda karena pengesahan BA
     * dipisah. Kalau suatu saat digabung jadi satu aksi, keduanya selalu sama dan dua
     * kartu di dashboard jadi satu angka yang ditulis dua kali.
     */
    public function test_antrean_ba_kejadian_dan_ba_kerusakan_bukan_angka_yang_sama(): void
    {
        // Belum disentuh sama sekali → cuma antre BA Kejadian.
        $this->report();
        $this->report();

        // BA Kejadian sudah disahkan → pindah ke antrean BA Kerusakan.
        $sudahBak = $this->report();
        $this->sahkanBak($sudahBak);

        $summary = $this->summary();

        $this->assertSame(2, $summary['incident_ba_queue']);
        $this->assertSame(1, $summary['damage_ba_queue']);
        $this->assertNotSame($summary['incident_ba_queue'], $summary['damage_ba_queue']);
    }

    public function test_laporan_tanpa_kerusakan_tidak_pernah_masuk_antrean_ba_kerusakan(): void
    {
        $report = $this->report(['analyze' => 'Tidak Ada Kerusakan Aset', 'damaged_items' => []]);
        $this->sahkanBak($report);

        $summary = $this->summary();

        $this->assertSame(0, $summary['incident_ba_queue']);
        $this->assertSame(0, $summary['damage_ba_queue']);
    }

    public function test_dokumen_tertunda_menghitung_yang_suratnya_belum_masuk(): void
    {
        $verify = app(IncidentVerificationService::class);

        $menunggu = $this->report(['relief_requested' => true, 'settlement_type' => 'Tunai / Transfer']);
        $this->sahkanBak($menunggu);
        $verify->validateDamageBa($menunggu->refresh(), $this->mcs);

        $this->assertSame(1, $this->summary()['pending_relief_letter']);

        // Suratnya masuk → keluar dari hitungan. Ini yang membuktikan yang dihitung
        // memang "belum menyerahkan", bukan sekadar "mengajukan keringanan".
        Document::create([
            'documentable_type' => 'incident_report',
            'documentable_id' => $menunggu->uuid,
            'document_type' => 'relief_letter',
            'path' => "insiden/{$menunggu->uuid}/surat.pdf",
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'uploaded_by' => $this->officer1->id,
            'uploaded_at' => now(),
        ]);

        $this->assertSame(0, $this->summary()['pending_relief_letter']);
    }

    public function test_dokumen_tertunda_belum_dihitung_sebelum_ba_kerusakan_disahkan(): void
    {
        // Tenggatnya damage_ba.validated_at + 7 hari — sebelum BA Kerusakan disahkan,
        // tenggatnya belum mulai berjalan dan tidak ada yang tertunda.
        $report = $this->report(['relief_requested' => true, 'settlement_type' => 'Tunai / Transfer']);
        $this->sahkanBak($report);

        $this->assertSame(0, $this->summary()['pending_relief_letter']);
    }

    public function test_total_estimasi_menghitung_yang_nilainya_sudah_ditetapkan(): void
    {
        $verify = app(IncidentVerificationService::class);

        $disahkan = $this->report();
        $this->sahkanBak($disahkan);
        $verify->validateDamageBa($disahkan->refresh(), $this->mcs);

        // Yang masih mengantre belum tentu jadi — tidak ikut dihitung.
        $this->report();

        // 2 x 1.976.600 = 3.953.200
        $this->assertSame('3953200.00', $this->summary()['estimation_this_month']);
    }

    /**
     * Nominal antrean menghitung berkas yang SAMA dengan damage_ba_queue — kartu
     * "1 Berkas · Rp 3.953.200" tidak boleh menjumlah berkas yang tidak dia hitung.
     */
    public function test_nominal_antrean_ba_kerusakan_ikut_filter_antreannya(): void
    {
        $this->assertSame('0.00', $this->summary()['damage_ba_queue_estimation']);

        $verify = app(IncidentVerificationService::class);

        $antre = $this->report();
        $this->sahkanBak($antre);

        $sudahSah = $this->report();
        $this->sahkanBak($sudahSah);
        $verify->validateDamageBa($sudahSah->refresh(), $this->mcs);

        $this->report(); // belum BAK — antre BA Kejadian, bukan BA Kerusakan

        $summary = $this->summary();

        $this->assertSame(1, $summary['damage_ba_queue']);
        // 2 x 1.976.600 — cuma laporan $antre
        $this->assertSame('3953200.00', $summary['damage_ba_queue_estimation']);
    }

    // ---- ?queue= di GET /incident-reports ----------------------------------------

    private function antrean(string $queue): array
    {
        $res = $this->actingAs($this->mcs, 'sanctum')
            ->getJson("/api/v1/incident-reports?queue={$queue}")
            ->assertOk();

        return [$res->json('meta.total'), array_column($res->json('data'), 'uuid')];
    }

    /**
     * Badge dashboard dan isi tab Verifikasi Laporan memakai definisi antrean yang SAMA
     * (scope IncidentReport). Laporan dari tiap cabang status dibuat sekali, lalu
     * dicek masuk ke tab yang benar — dan cuma ke situ.
     */
    public function test_isi_tab_antrean_sama_dengan_badge_dashboard(): void
    {
        $verify = app(IncidentVerificationService::class);

        $baru = $this->report();

        $bakDitolak = $this->report();
        $verify->rejectIncidentBa($bakDitolak, $this->mcs, ['rejection_reason' => 'Foto buram.']);

        $antreBar = $this->report();
        $this->sahkanBak($antreBar);

        $barDitolak = $this->report();
        $this->sahkanBak($barDitolak);
        $verify->rejectDamageBa($barDitolak->refresh(), $this->mcs, ['rejection_reason' => 'Volume salah.']);

        $selesai = $this->report();
        $this->sahkanBak($selesai);
        $verify->validateDamageBa($selesai->refresh(), $this->mcs);

        $tanpaKerusakan = $this->report(['analyze' => 'Tidak Ada Kerusakan Aset', 'damaged_items' => []]);
        $this->sahkanBak($tanpaKerusakan);

        $summary = $this->summary();
        [$totalBak, $bak] = $this->antrean('awaiting_bak');
        [$totalBar, $bar] = $this->antrean('awaiting_bar');

        $this->assertSame($summary['incident_ba_queue'], $totalBak);
        $this->assertSame($summary['damage_ba_queue'], $totalBar);

        $this->assertSame([$baru->uuid], $bak);
        // BAR ditolak (report_status 'rejected') TETAP di antrean BAR — yang dulu hilang
        // karena FE menyaring report_status=pending_verification.
        $this->assertEqualsCanonicalizing([$antreBar->uuid, $barDitolak->uuid], $bar);

        foreach ([$bakDitolak, $selesai, $tanpaKerusakan] as $bukanAntrean) {
            $this->assertNotContains($bukanAntrean->uuid, [...$bak, ...$bar]);
        }
    }

    public function test_tanpa_queue_filter_report_status_lama_tetap_jalan(): void
    {
        $this->report();
        $ditolak = $this->report();
        app(IncidentVerificationService::class)->rejectIncidentBa($ditolak, $this->mcs, ['rejection_reason' => 'Foto buram.']);

        $this->actingAs($this->mcs, 'sanctum')
            ->getJson('/api/v1/incident-reports?report_status=rejected')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.uuid', $ditolak->uuid);
    }

    /**
     * Arsip MCS = report_status closed, disaring bulan KEJADIAN. Laporan tanpa kerusakan
     * masuk arsip begitu BAK disahkan; yang BAR-nya sudah sah tapi WO-nya belum selesai
     * (verified) belum boleh ikut. Jalur closed lewat WO dijaga WorkOrderTest.
     */
    public function test_arsip_disaring_bulan_kejadian(): void
    {
        $tanpaBar = ['analyze' => 'Tidak Ada Kerusakan Aset', 'damaged_items' => []];

        $arsipSep = $this->report($tanpaBar + ['date_time' => '2026-09-30 23:30:00']);
        $this->sahkanBak($arsipSep);

        $arsipAgu = $this->report($tanpaBar + ['date_time' => '2026-08-31 23:59:00']);
        $this->sahkanBak($arsipAgu);

        $menungguWo = $this->report(['date_time' => '2026-09-10 10:00:00']);
        $this->sahkanBak($menungguWo);
        app(IncidentVerificationService::class)->validateDamageBa($menungguWo->refresh(), $this->mcs);

        $this->actingAs($this->mcs, 'sanctum')
            ->getJson('/api/v1/incident-reports?report_status=closed&month=2026-09')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.uuid', $arsipSep->uuid);

        $this->actingAs($this->mcs, 'sanctum')
            ->getJson('/api/v1/incident-reports?month=2026-13')
            ->assertStatus(422);
    }

    /**
     * Tab "Semua" Verifikasi Laporan (queue=active) membuang closed — tempatnya di Arsip —
     * tapi verified tetap ikut karena WO-nya masih berjalan. Tanpa parameter closed tetap
     * ikut: Daftar Laporan petugas memakai route yang sama.
     */
    public function test_queue_active_membuang_closed_tapi_default_tidak(): void
    {
        $baru = $this->report();

        $verified = $this->report();
        $this->sahkanBak($verified);
        app(IncidentVerificationService::class)->validateDamageBa($verified->refresh(), $this->mcs);

        $closed = $this->report(['analyze' => 'Tidak Ada Kerusakan Aset', 'damaged_items' => []]);
        $this->sahkanBak($closed);

        [$total, $aktif] = $this->antrean('active');
        $this->assertSame(2, $total);
        $this->assertEqualsCanonicalizing([$baru->uuid, $verified->uuid], $aktif);

        $this->actingAs($this->mcs, 'sanctum')
            ->getJson('/api/v1/incident-reports')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_queue_di_luar_daftar_ditolak(): void
    {
        $this->actingAs($this->mcs, 'sanctum')
            ->getJson('/api/v1/incident-reports?queue=semua')
            ->assertStatus(422);
    }

    // ---- Masa tenggang surat keringanan ------------------------------------------

    /** Laporan mengajukan keringanan, dua BA-nya disahkan pada jam yang ditentukan. */
    private function keringananDisahkan(string $waktuSah, array $override = []): IncidentReport
    {
        $report = $this->report($override + ['relief_requested' => true, 'settlement_type' => 'Tunai / Transfer']);

        $this->travelTo(Carbon::parse($waktuSah));
        $this->sahkanBak($report);
        app(IncidentVerificationService::class)->validateDamageBa($report->refresh(), $this->mcs);
        $this->travelBack();

        return $report->refresh();
    }

    /**
     * Halaman Pantauan Masa Tenggang dan badge "Dokumen Keringanan" memakai definisi
     * yang SAMA, dan daftarnya mulai dari tenggat terdekat — yang paling sebentar lagi
     * lewat itulah yang dikejar MCS.
     */
    public function test_antrean_masa_tenggang_sama_dengan_badge_dan_urut_tenggat_terdekat(): void
    {
        $belakangan = $this->keringananDisahkan('2026-09-30 10:00:00');
        $duluan = $this->keringananDisahkan('2026-09-27 08:00:00');

        $tanpaKeringanan = $this->report();
        $this->sahkanBak($tanpaKeringanan);
        app(IncidentVerificationService::class)->validateDamageBa($tanpaKeringanan->refresh(), $this->mcs);

        $barBelumSah = $this->report(['relief_requested' => true, 'settlement_type' => 'Tunai / Transfer']);
        $this->sahkanBak($barBelumSah);

        [$total, $isi] = $this->antrean('awaiting_relief_letter');

        $this->assertSame($this->summary()['pending_relief_letter'], $total);
        $this->assertSame([$duluan->uuid, $belakangan->uuid], $isi);
    }

    public function test_tenggat_surat_dihitung_server_dari_ba_kerusakan_disahkan(): void
    {
        $report = $this->keringananDisahkan('2026-09-27 08:00:00');

        $this->actingAs($this->mcs, 'sanctum')
            ->getJson('/api/v1/incident-reports?queue=awaiting_relief_letter')
            ->assertOk()
            ->assertJsonPath(
                'data.0.incident_ba.damage_ba.relief_deadline',
                Carbon::parse('2026-09-27 08:00:00')->addDays(config('toll.relief_grace_days'))->toJSON(),
            );

        // Detail laporan membawa angka yang sama — FE tidak perlu menghitung +7 sendiri.
        $this->actingAs($this->mcs, 'sanctum')
            ->getJson("/api/v1/incident-reports/{$report->uuid}")
            ->assertOk()
            ->assertJsonPath(
                'data.report.incident_ba.damage_ba.relief_deadline',
                Carbon::parse('2026-10-04 08:00:00')->toJSON(),
            );
    }

    private function unggahSurat(User $oleh, IncidentReport $report)
    {
        return $this->actingAs($oleh, 'sanctum')
            ->post("/api/v1/incident-reports/{$report->uuid}/relief-letter", [
                'file_uuid' => (string) Str::uuid(),
                'file' => UploadedFile::fake()->create('surat-keringanan.pdf', 120, 'application/pdf'),
            ], ['Accept' => 'application/json']);
    }

    /**
     * Surat yang dibawa pengguna jalan ke kantor dilampirkan MCS. Akibatnya CUMA satu:
     * laporan keluar dari antrean — status dan nilai BA tidak berubah. Petugas pemilik
     * laporan tetap bisa membuka suratnya, walau bukan dia yang mengunggah.
     */
    public function test_mcs_mengunggah_surat_keringanan_dan_laporan_keluar_dari_antrean(): void
    {
        $report = $this->keringananDisahkan('2026-09-27 08:00:00');
        $sebelum = $report->only(['report_status', 'total_estimation', 'pledged_amount']);

        $id = $this->unggahSurat($this->mcs, $report)
            ->assertOk()
            ->assertJsonPath('data.document.document_type', 'relief_letter')
            ->assertJsonPath('data.document.uploaded_by', $this->mcs->id)
            ->json('data.document.id');

        $this->assertSame(0, $this->summary()['pending_relief_letter']);
        $this->assertSame($sebelum, $report->refresh()->only(['report_status', 'total_estimation', 'pledged_amount']));

        $this->actingAs($this->officer1, 'sanctum')
            ->get("/api/v1/documents/{$id}")
            ->assertOk();
    }

    public function test_surat_keringanan_ditolak_kalau_laporan_tidak_mengajukan_keringanan(): void
    {
        $report = $this->report();
        $this->sahkanBak($report);

        $this->unggahSurat($this->mcs, $report)
            ->assertStatus(422)
            ->assertJsonValidationErrors('relief_requested');
    }

    public function test_route_unggah_surat_mcs_tertutup_untuk_petugas_lapangan(): void
    {
        $report = $this->keringananDisahkan('2026-09-27 08:00:00');

        $this->unggahSurat($this->officer1, $report)->assertStatus(403);
    }

    public function test_petugas_lapangan_tidak_boleh_membuka_dashboard_mcs(): void
    {
        $this->actingAs($this->officer1, 'sanctum')
            ->getJson('/api/v1/mcs/summary')
            ->assertStatus(403);
    }
}
