<?php

namespace Tests\Feature;

use App\Models\AssetCatalog;
use App\Models\AssetType;
use App\Models\CatalogPrice;
use App\Models\IncidentReport;
use App\Models\OperationalTeam;
use App\Models\PatrolReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderReview;
use App\Services\Shared\IncidentReportSyncService;
use App\Services\Mcs\IncidentVerificationService;
use App\Services\Shared\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 4 dan Fase 6 — penerbitan Work Order, penunjukan regu, evaluasi, penutupan.
 */
class WorkOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $officer1;

    private User $officer2;

    private User $mcs;

    private User $unit;

    /** @var array<int, User> */
    private array $crew;

    private CatalogPrice $price;

    private AssetCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->officer1 = $this->user('JMTO001');
        $this->officer2 = $this->user('JMTO002');
        $this->mcs = $this->user('JMTO003', 'mcs_supervisor');
        $this->unit = $this->user('JMJ001', 'maintenance_unit');
        $this->crew = [
            $this->user('JMTM001', 'maintenance_officer'),
            $this->user('JMTM002', 'maintenance_officer'),
        ];

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

    // =====================================================================
    // Batas role — allowlist, bukan denylist
    // =====================================================================

    /**
     * Petugas patroli dan MCS ditolak di SETIAP endpoint Work Order, bukan cuma di satu
     * yang kebetulan dites. Denylist yang lolos di satu route sudah cukup untuk memberi
     * petugas patroli kemampuan menutup Work Order.
     *
     * `maintenance_officer` SENGAJA TIDAK ADA di daftar ini sejak Fase 5 (3 Sep) — dia
     * punya batasnya sendiri, dan itu diuji di test berikutnya. Sebelumnya dia ikut di
     * sini karena aplikasinya memang belum ada.
     */
    public function test_role_di_luar_maintenance_ditolak_di_semua_endpoint(): void
    {
        $workOrder = $this->terbitkanDariInsiden();

        $rute = [
            ['getJson', '/api/v1/maintenance/summary'],
            ['getJson', '/api/v1/maintenance/queue'],
            ['getJson', '/api/v1/maintenance/officers'],
            ['getJson', '/api/v1/work-orders'],
            ['getJson', "/api/v1/work-orders/{$workOrder->id}"],
            ['postJson', '/api/v1/work-orders'],
            ['putJson', "/api/v1/work-orders/{$workOrder->id}/team"],
            ['postJson', "/api/v1/work-orders/{$workOrder->id}/review"],
        ];

        foreach ([$this->officer1, $this->mcs] as $orang) {
            foreach ($rute as [$method, $url]) {
                $this->actingAs($orang, 'sanctum')->$method($url)
                    ->assertStatus(403, "{$orang->role} seharusnya ditolak di {$url}");
            }
        }
    }

    /**
     * Petugas maintenance MEMBACA pekerjaannya, tapi tidak menugaskan, tidak menerbitkan,
     * dan tidak mengevaluasi.
     *
     * Batasnya dua lapis, dan dua-duanya perlu: `scopeVisibleTo` menyaring DAFTAR,
     * `WorkOrderPolicy` menjaga DETAIL. Daftar yang disaring tanpa penjaga di detailnya
     * cuma menyembunyikan tautannya — id-nya bilangan bulat berurutan, dan menebaknya
     * tidak butuh keahlian apa pun.
     */
    public function test_petugas_maintenance_membaca_pekerjaannya_tapi_tidak_menugaskan(): void
    {
        $punyaDia = $this->terbitkanDariInsiden();
        $punyaReguLain = $this->terbitkanDariInsiden(assignees: [$this->crew[1]->id]);

        $petugas = $this->crew[0];

        // Daftar: cuma pekerjaannya sendiri yang muncul.
        $daftar = $this->actingAs($petugas, 'sanctum')->getJson('/api/v1/work-orders')
            ->assertOk()->json('data');
        $this->assertSame([$punyaDia->id], array_column($daftar, 'id'));

        // Detail: miliknya boleh, milik regu lain 403 — bukan 404 yang menyamar.
        $this->actingAs($petugas, 'sanctum')->getJson("/api/v1/work-orders/{$punyaDia->id}")->assertOk();
        $this->actingAs($petugas, 'sanctum')->getJson("/api/v1/work-orders/{$punyaReguLain->id}")->assertStatus(403);

        // Yang tetap tertutup untuknya: menerbitkan, menugaskan, mengevaluasi, dan
        // seluruh layar Unit Maintenance.
        foreach ([
            ['getJson', '/api/v1/maintenance/summary'],
            ['getJson', '/api/v1/maintenance/queue'],
            ['getJson', '/api/v1/maintenance/officers'],
            ['postJson', '/api/v1/work-orders'],
            ['putJson', "/api/v1/work-orders/{$punyaDia->id}/team"],
            ['postJson', "/api/v1/work-orders/{$punyaDia->id}/review"],
        ] as [$method, $url]) {
            $this->actingAs($petugas, 'sanctum')->$method($url)
                ->assertStatus(403, "petugas maintenance seharusnya ditolak di {$url}");
        }
    }

    // =====================================================================
    // Antrean
    // =====================================================================

    /**
     * Antreannya BA Kerusakan tervalidasi + belum ada WO. Dua-duanya, bukan salah satu:
     * yang BA-nya belum sah belum tentu jadi pekerjaan, dan yang sudah ber-WO sudah ada
     * yang mengerjakan.
     */
    public function test_antrean_hanya_memuat_kerusakan_tervalidasi_yang_belum_ada_wonya(): void
    {
        // Belum disahkan sama sekali.
        $this->laporan();

        // BA Kejadian saja — BA Kerusakan belum, jadi belum jadi pekerjaan.
        $this->sahkanBaKejadian($this->laporan());

        $siap = $this->laporanTervalidasi();
        $sudahDikerjakan = $this->laporanTervalidasi();
        $this->terbitkanDariInsiden($sudahDikerjakan);

        $data = $this->actingAs($this->unit, 'sanctum')
            ->getJson('/api/v1/maintenance/queue')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($siap->uuid, $data[0]['uuid']);
    }

    /**
     * Kerusakan yang SUDAH punya WO tidak boleh ikut terhitung di kartu antrean laporan
     * yang masih menyisakan baris lain — kalau ikut, angkanya menghitung pekerjaan yang
     * sedang dikerjakan sebagai pekerjaan yang belum dimulai.
     */
    public function test_antrean_hanya_memuat_baris_kerusakan_yang_belum_ditempeli_wo(): void
    {
        // Tiga baris, satu di antaranya sudah dikerjakan. Dengan satu baris saja
        // laporannya hilang sama sekali dari antrean dan test ini akan hijau tanpa
        // membuktikan apa pun soal penyaringan per baris.
        $report = $this->laporanTervalidasi(['damaged_items' => $this->items(3)]);
        $report->damagedItems()->first()->update(['work_order_id' => $this->terbitkanManual()->id]);

        $data = $this->actingAs($this->unit, 'sanctum')
            ->getJson('/api/v1/maintenance/queue')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertCount($report->damagedItems()->count() - 1, $data[0]['damaged_items']);
    }

    // =====================================================================
    // Penerbitan — cabang insiden
    // =====================================================================

    /**
     * Lokasi DISALIN dari laporan, tidak diketik ulang. Sebelum
     * incident_report.location_type ada, Alur 4.2 mengisi WO dengan 'mainline' begitu
     * saja — dan insiden di ramp keluar mengirim regu perbaikan ke jalur utama.
     */
    public function test_wo_dari_insiden_menyalin_lokasi_laporan_dan_menurunkan_total_m(): void
    {
        $report = $this->laporanTervalidasi(['km' => 17, 'm' => 640, 'route' => 'B', 'lane' => 'Lajur 3', 'location_type' => 'Ramp Keluar']);

        $workOrder = $this->terbitkanDariInsiden($report);

        $this->assertSame('Ramp Keluar', $workOrder->location_type);
        $this->assertSame(17, $workOrder->km);
        $this->assertSame(640, $workOrder->m);
        $this->assertSame('B', $workOrder->route);
        $this->assertSame('Lajur 3', $workOrder->lane);
        // total_m tidak ada di incident_report, jadi harus diturunkan — dia yang dibaca
        // index (route, total_m) untuk analisis titik rawan.
        $this->assertSame(17640, $workOrder->total_m);
    }

    /**
     * Titik peta "Lokasi Presisi" ikut disalin, sama seperti KM-nya: sumbernya GPS HP
     * petugas saat melapor. WO manual boleh mengirimnya sendiri, tapi harus sepasang.
     */
    public function test_koordinat_peta_disalin_dari_laporan_dan_harus_sepasang(): void
    {
        $report = $this->laporanTervalidasi(['latitude' => -7.5612345, 'longitude' => 110.7712345]);

        $workOrder = $this->terbitkanDariInsiden($report);

        $this->assertSame(-7.5612345, $workOrder->latitude);
        $this->assertSame(110.7712345, $workOrder->longitude);

        $this->postWo($this->manualPayload(['latitude' => -7.6]))
            ->assertStatus(422)->assertJsonValidationErrors('longitude');
        $this->postWo($this->manualPayload(['latitude' => -7.6, 'longitude' => 110.5]))
            ->assertOk()->assertJsonPath('data.work_order.latitude', -7.6);
    }

    /**
     * Satu WO = satu titik lokasi, menutup SEMUA komponen di titik itu. Satu WO per baris
     * kerusakan berarti empat regu dikirim ke KM yang sama untuk satu kunjungan.
     */
    public function test_wo_dari_insiden_menempel_semua_baris_kerusakan_sekaligus(): void
    {
        $report = $this->laporanTervalidasi(['damaged_items' => $this->items(3)]);

        $workOrder = $this->terbitkanDariInsiden($report);

        $this->assertSame(3, $workOrder->damagedItems()->count());
        $this->assertSame(0, $report->damagedItems()->whereNull('work_order_id')->count());
    }

    /**
     * asset_catalog_id sengaja NULL untuk WO dari insiden — komponennya dibaca dari
     * damaged_items. Dua sumber kebenaran yang bisa bertentangan lebih buruk daripada satu
     * kolom kosong, jadi kirimannya DITOLAK, bukan diabaikan diam-diam.
     */
    public function test_wo_dari_insiden_menolak_asset_catalog_id(): void
    {
        $report = $this->laporanTervalidasi();

        $this->postWo(['incident_report_id' => $report->uuid, 'asset_catalog_id' => $this->catalog->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.asset_catalog_id.0', fn ($p) => str_contains($p, 'daftar kerusakan'));
    }

    /**
     * Pemicunya validasi BA KERUSAKAN, bukan pengesahan BA Kejadian dan bukan terbitnya
     * tagihan. Dijawab 422 berpesan, bukan dibiarkan mati di FK violation
     * damage_minutes_id.
     */
    public function test_wo_tidak_bisa_terbit_sebelum_ba_kerusakan_disahkan(): void
    {
        $report = $this->laporan();
        $this->sahkanBaKejadian($report);

        $this->postWo(['incident_report_id' => $report->uuid])
            ->assertStatus(422)
            ->assertJsonPath('errors.incident_report_id.0', fn ($p) => str_contains($p, 'BA Kerusakan'));
    }

    /**
     * Tanpa penjaga ini terbit WO yang menutup NOL baris: nomornya terpakai, regunya
     * ditugaskan, dan tidak ada satu pun komponen yang harus dikerjakan.
     */
    public function test_wo_kedua_dari_laporan_yang_sama_ditolak_kalau_tidak_ada_sisa_kerusakan(): void
    {
        $report = $this->laporanTervalidasi();
        $this->terbitkanDariInsiden($report);

        $this->postWo(['incident_report_id' => $report->uuid])
            ->assertStatus(422)
            ->assertJsonPath('errors.incident_report_id.0', fn ($p) => str_contains($p, 'sudah punya Work Order'));
    }

    /**
     * TABRAK LARI TETAP DIPERBAIKI. Kerusakannya ada, yang ditagih tidak ada — dan itu
     * persis kasus yang membuktikan pemicu WO tidak boleh berupa surat tagihan. Kalau
     * test ini merah, seseorang menggantung Work Order ke `is_identified`.
     */
    public function test_tabrak_lari_tetap_masuk_antrean_dan_tetap_bisa_diterbitkan_wonya(): void
    {
        $report = $this->laporanTervalidasi(['is_identified' => false, 'culprits' => []]);

        $data = $this->actingAs($this->unit, 'sanctum')
            ->getJson('/api/v1/maintenance/queue')->assertOk()->json('data');
        $this->assertSame($report->uuid, $data[0]['uuid']);

        $this->assertSame(WorkOrder::TRIGGER_INSIDEN, $this->terbitkanDariInsiden($report)->trigger_source);
    }

    // =====================================================================
    // Penerbitan — cabang manual
    // =====================================================================

    /**
     * Kebalikan dari cabang insiden: tidak ada damaged_items untuk dibaca, jadi jenis
     * asetnya WAJIB disebut. Tanpa itu WO manual cuma berisi kalimat bebas dan tidak bisa
     * dihubungkan ke katalog mana pun.
     */
    public function test_wo_manual_mewajibkan_asset_catalog_id_dan_lokasi(): void
    {
        $this->actingAs($this->unit, 'sanctum')
            ->postJson('/api/v1/work-orders', [
                'trigger_source' => 'Temuan Patroli',
                'damage_description' => 'Lampu PJU mati',
                'priority' => 'Rendah',
                'target_date' => Carbon::tomorrow()->toDateString(),
                'assignee_ids' => [$this->crew[0]->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asset_catalog_id', 'km', 'route', 'location_type']);
    }

    /**
     * `km` bertipe smallint (maks 32767). Tanpa batas atas di validasi, `km: 40000` LOLOS
     * lalu mati di Postgres dengan SQLSTATE 22003 — 500, dan teks error SQL bocor ke klien
     * sebagai `message` yang seharusnya "kalimat siap tampil".
     *
     * Batasnya batas KOLOM, bukan panjang ruas: Mentok #2 belum menjawab apakah KM dihitung
     * dari Kartasura atau lanjutan Trans-Jawa, jadi membatasi di ±97 bisa menolak data sah.
     */
    public function test_km_di_luar_jangkauan_kolom_ditolak_422_bukan_meledak_500(): void
    {
        $this->actingAs($this->unit, 'sanctum')
            ->postJson('/api/v1/work-orders', $this->manualPayload(['km' => 40000]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['km']);

        // Batasnya sendiri harus tetap diterima — kalau tidak, penjaganya kelewat ketat.
        $this->actingAs($this->unit, 'sanctum')
            ->postJson('/api/v1/work-orders', $this->manualPayload(['km' => 32767]))
            ->assertOk();
    }

    /**
     * Daftar pelaksana raksasa TIDAK boleh memicu satu query `exists` per elemen.
     *
     * `max:50` sendirian tidak menolong: Laravel tetap menjalankan aturan `assignee_ids.*`
     * untuk setiap elemen walau aturan arraynya sudah gagal. Diukur sebelum diperbaiki:
     * 2.000 entri = 2.000 query, balasan 155 KB berisi 2.000 pesan, 5,2 detik untuk satu
     * request kecil. Yang dijaga di sini penguatan request, bukan berapa orang yang boleh
     * ditugaskan.
     */
    public function test_daftar_pelaksana_raksasa_ditolak_dengan_satu_pesan_bukan_ribuan(): void
    {
        $response = $this->actingAs($this->unit, 'sanctum')
            ->postJson('/api/v1/work-orders', $this->manualPayload([
                'assignee_ids' => range(1000, 2999),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assignee_ids']);

        $jumlahPesan = collect($response->json('errors'))->flatten()->count();

        $this->assertSame(1, $jumlahPesan,
            "Kiriman 2.000 pelaksana menghasilkan {$jumlahPesan} pesan error — aturan per-elemen masih jalan.");
    }

    public function test_wo_manual_tidak_boleh_menunjuk_laporan(): void
    {
        $report = $this->laporanTervalidasi();

        $this->actingAs($this->unit, 'sanctum')
            ->postJson('/api/v1/work-orders', $this->manualPayload(['incident_report_id' => $report->uuid]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['incident_report_id']);
    }

    /**
     * Alur 4.3: cost_amount dibiarkan kosong. Laporannya cuma "lampu KM 45+200 mati";
     * angkanya baru muncul setelah regu menghitung, dan mewajibkannya di sini akan
     * memacetkan setiap temuan patroli di tahap pembuatan.
     */
    public function test_wo_manual_terbit_tanpa_biaya_dan_tanpa_ba(): void
    {
        $workOrder = $this->terbitkanManual();

        $this->assertNull($workOrder->cost_amount);
        $this->assertNull($workOrder->incident_report_id);
        $this->assertNull($workOrder->damage_minutes_id);
        $this->assertSame($this->catalog->id, $workOrder->asset_catalog_id);
    }

    /**
     * patrol_report_id cuma passthrough — Unit Maintenance tetap mengetik asset_catalog_id
     * dan lokasi sendiri seperti WO manual biasa, server cuma menyimpan tautannya.
     */
    public function test_wo_dari_patroli_menyimpan_tautan_dan_hilang_dari_antrean(): void
    {
        $patrol = $this->patrolReport();

        $this->actingAs($this->unit, 'sanctum')
            ->postJson('/api/v1/work-orders', $this->manualPayload(['patrol_report_id' => $patrol->uuid]))
            ->assertOk();

        $this->assertSame($patrol->uuid, WorkOrder::sole()->patrol_report_id);

        $this->actingAs($this->unit, 'sanctum')->getJson('/api/v1/maintenance/patrol-queue')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    /** Satu laporan patroli = satu Work Order — dua penerbitan beruntun ditolak. */
    public function test_laporan_patroli_yang_sudah_punya_wo_ditolak(): void
    {
        $patrol = $this->patrolReport();

        $this->actingAs($this->unit, 'sanctum')
            ->postJson('/api/v1/work-orders', $this->manualPayload(['patrol_report_id' => $patrol->uuid]))
            ->assertOk();

        $this->actingAs($this->unit, 'sanctum')
            ->postJson('/api/v1/work-orders', $this->manualPayload(['patrol_report_id' => $patrol->uuid]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['patrol_report_id']);
    }

    private function patrolReport(): PatrolReport
    {
        return PatrolReport::create([
            'officer_id' => $this->officer1->id,
            'patrolled_at' => now()->subHour(),
            'route' => 'B',
            'is_damaged' => true,
            'asset_catalog_id' => $this->catalog->id,
            'location_type' => 'Jalur Utama',
            'km' => 45,
            'm' => 200,
            'lane' => 'Bahu Jalan',
            'damage_description' => 'Guardrail penyok, kemungkinan usia pakai.',
            'sync_status' => 'sent',
        ]);
    }

    // =====================================================================
    // Nomor
    // =====================================================================

    /**
     * Formatnya BEDA dari nomor Berita Acara, dan urutannya sendiri. Sisipan JMTM yang
     * membedakan perintah kerja dari Berita Acara di kertas yang dipegang regu.
     */
    public function test_nomor_wo_pakai_format_jmtm_dan_urutan_sendiri(): void
    {
        $report = $this->laporanTervalidasi();
        $bulan = Carbon::now()->format('n');
        $romawi = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$bulan - 1];
        $tahun = Carbon::now()->year;

        $pertama = $this->terbitkanDariInsiden($report);
        $kedua = $this->terbitkanManual();

        // Nomor BA sudah terbit lebih dulu untuk laporan yang sama, tapi counter WO tetap
        // mulai dari 001 — kalau bertiga berbagi counter, angkanya akan melompat.
        $this->assertSame("001/JMTM/TM.JOGLO/{$romawi}/{$tahun}", $pertama->reference_number);
        $this->assertSame("002/JMTM/TM.JOGLO/{$romawi}/{$tahun}", $kedua->reference_number);
        $this->assertStringNotContainsString('JMTM', $report->fresh()->report_number);
    }

    public function test_nomor_wo_reset_per_tahun(): void
    {
        $this->terbitkanManual();

        Carbon::setTestNow(Carbon::now()->addYear());
        $tahunDepan = $this->terbitkanManual();
        Carbon::setTestNow();

        $this->assertStringStartsWith('001/JMTM/', $tahunDepan->reference_number);
    }

    // =====================================================================
    // Regu
    // =====================================================================

    /**
     * ERD: "JANGAN pernah menulis nol baris" — WO yang ditugaskan tanpa pelaksana tidak
     * muncul di daftar siapa pun, dan tidak ada yang pernah tahu dia ada.
     */
    public function test_wo_tidak_bisa_terbit_tanpa_pelaksana(): void
    {
        $report = $this->laporanTervalidasi();

        $this->postWo(['incident_report_id' => $report->uuid, 'assignee_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assignee_ids']);
    }

    /**
     * Petugas patroli JMTO bukan pelaksana perbaikan JMTM. Ditolak, bukan diterima
     * diam-diam: barisnya sah, orangnya ada, cuma pekerjaannya bukan pekerjaan dia — dan
     * itu tidak akan pernah kelihatan dari data.
     */
    public function test_pelaksana_harus_petugas_maintenance_yang_aktif(): void
    {
        $report = $this->laporanTervalidasi();
        $nonaktif = $this->user('JMTM009', 'maintenance_officer');
        $nonaktif->update(['is_active' => false]);

        $this->postWo(['incident_report_id' => $report->uuid, 'assignee_ids' => [$this->officer1->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['assignee_ids.0']);

        $this->postWo(['incident_report_id' => $report->uuid, 'assignee_ids' => [$nonaktif->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['assignee_ids.0']);
    }

    /**
     * Orang yang sama dua kali dijawab 422 berpesan, bukan 500 dari unique index
     * (work_order_id, user_id) yang pesannya cuma nama constraint Postgres.
     */
    public function test_pelaksana_yang_sama_dua_kali_ditolak_dengan_pesan(): void
    {
        $report = $this->laporanTervalidasi();

        $this->postWo([
            'incident_report_id' => $report->uuid,
            'assignee_ids' => [$this->crew[0]->id, $this->crew[0]->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['assignee_ids.0']);
    }

    public function test_ganti_regu_mengganti_seluruhnya_bukan_menambah(): void
    {
        $workOrder = $this->terbitkanDariInsiden(assignees: [$this->crew[0]->id, $this->crew[1]->id]);

        $this->actingAs($this->unit, 'sanctum')
            ->putJson("/api/v1/work-orders/{$workOrder->id}/team", ['assignee_ids' => [$this->crew[1]->id]])
            ->assertOk();

        $this->assertSame([$this->crew[1]->id], $workOrder->operationalTeam()->pluck('user_id')->all());
    }

    /**
     * Keputusan PO 5 Okt: regu boleh diganti selama pekerjaan berjalan (tambah tenaga,
     * ganti teknisi yang berhalangan), tapi tidak saat sedang dievaluasi atau sudah
     * selesai — dan KOORDINATORNYA terkunci: dia cuma berganti lewat oper shift.
     */
    public function test_regu_boleh_diganti_selama_berjalan_tapi_koordinatornya_terkunci(): void
    {
        $workOrder = $this->terbitkanDariInsiden();  // regu satu orang → crew[0] koordinator
        $workOrder->update(['repair_status' => 'in_progress']);
        $ganti = fn (array $body) => $this->actingAs($this->unit, 'sanctum')
            ->putJson("/api/v1/work-orders/{$workOrder->id}/team", $body);

        // Tambah teknisi tanpa menyebut koordinator → koordinator lama dipertahankan.
        $ganti(['assignee_ids' => [$this->crew[0]->id, $this->crew[1]->id]])->assertOk();
        $this->assertSame($this->crew[0]->id, $workOrder->coordinatorId());

        // Mengganti atau mengeluarkan koordinator ditolak.
        $ganti(['assignee_ids' => [$this->crew[0]->id, $this->crew[1]->id], 'coordinator_id' => $this->crew[1]->id])
            ->assertStatus(422)->assertJsonValidationErrors(['coordinator_id']);
        $ganti(['assignee_ids' => [$this->crew[1]->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['coordinator_id']);

        foreach (['submitted', 'completed'] as $status) {
            $workOrder->update(['repair_status' => $status]);
            $ganti(['assignee_ids' => [$this->crew[0]->id]])
                ->assertStatus(422)->assertJsonValidationErrors(['repair_status']);
        }
    }

    /**
     * Koordinator wajib untuk regu lebih dari satu orang; regu satu orang otomatis
     * koordinatornya sendiri (keputusan PO 5 Okt).
     */
    public function test_koordinator_wajib_kecuali_regu_satu_orang(): void
    {
        $this->postWo($this->manualPayload(['assignee_ids' => [$this->crew[0]->id, $this->crew[1]->id]]))
            ->assertStatus(422)->assertJsonPath('errors.coordinator_id.0', 'Pilih koordinator regu.');

        $id = $this->postWo($this->manualPayload())->assertOk()->json('data.work_order.id');
        $this->assertSame($this->crew[0]->id, WorkOrder::find($id)->coordinatorId());
    }

    /**
     * CHECK num_nonnulls(incident_report_id, work_order_id) = 1: baris regu perbaikan
     * menunjuk WO saja, walau laporannya ada. Kalau keduanya diisi, barisnya punya dua
     * induk dan tidak ada yang tahu dia regu TKP atau regu perbaikan.
     */
    public function test_baris_regu_perbaikan_tidak_ikut_menunjuk_laporan(): void
    {
        $workOrder = $this->terbitkanDariInsiden();

        $baris = OperationalTeam::where('work_order_id', $workOrder->id)->get();

        $this->assertNotEmpty($baris);
        $baris->each(fn (OperationalTeam $row) => $this->assertNull($row->incident_report_id));
    }

    // =====================================================================
    // Fase 6 — evaluasi dan penutupan
    // =====================================================================

    public function test_evaluasi_ditolak_kalau_regu_belum_mengajukan_selesai(): void
    {
        $workOrder = $this->terbitkanDariInsiden();

        $this->postReview($workOrder, ['outcome' => 'Diterima'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['repair_status']);
    }

    /**
     * MENAMBAH BARIS, TIDAK PERNAH MENIMPA. Berapa kali sebuah pekerjaan dikembalikan
     * adalah angka paling berguna untuk menilai mutu regu, dan menimpa menghapusnya.
     */
    public function test_evaluasi_berulang_menumpuk_barisnya_bukan_menimpa(): void
    {
        $workOrder = $this->siapDievaluasi();

        $this->postReview($workOrder, ['outcome' => 'Dikerjakan Ulang', 'rejection_notes' => 'Blocking piece kurang satu.'])
            ->assertOk();
        $this->assertSame('in_progress', $workOrder->fresh()->repair_status);

        // refresh() dulu: Service sudah mengubah statusnya jadi `in_progress` di database,
        // tapi model di memori masih memegang `submitted` dari setup — dan Eloquent
        // MELEWATI update yang nilainya sama dengan yang dia kira sekarang, tanpa error.
        $workOrder->refresh()->update(['repair_status' => 'submitted']);
        $this->postReview($workOrder, ['outcome' => 'Diterima'])->assertOk();

        $this->assertSame(2, WorkOrderReview::where('work_order_id', $workOrder->id)->count());
        $this->assertSame(
            ['Dikerjakan Ulang', 'Diterima'],
            WorkOrderReview::where('work_order_id', $workOrder->id)->orderBy('id')->pluck('outcome')->all(),
        );
    }

    public function test_mengembalikan_pekerjaan_wajib_menyebut_alasannya(): void
    {
        $workOrder = $this->siapDievaluasi();

        $this->postReview($workOrder, ['outcome' => 'Dikerjakan Ulang'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rejection_notes']);
    }

    /**
     * Biaya WO dari insiden diturunkan dari SUM(subtotal) — angka yang sudah disepakati
     * bersama pengguna jalan dan tercetak di BA Kerusakan. Diketik ulang di sini, biaya
     * perbaikan boleh berbeda dari yang ditagih tanpa ada yang bisa melihat selisihnya.
     */
    public function test_penutupan_wo_insiden_mengambil_biaya_dari_daftar_kerusakan(): void
    {
        $report = $this->laporanTervalidasi(['damaged_items' => $this->items(2)]);
        $workOrder = $this->siapDievaluasi($report);

        $this->postReview($workOrder, ['outcome' => 'Diterima'])->assertOk();

        $this->assertSame(
            (float) $report->damagedItems()->sum('subtotal'),
            (float) $workOrder->fresh()->cost_amount,
        );
    }

    public function test_penutupan_wo_insiden_menolak_biaya_yang_diketik_manual(): void
    {
        $workOrder = $this->siapDievaluasi();

        $this->postReview($workOrder, ['outcome' => 'Diterima', 'cost_amount' => 1000])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cost_amount']);
    }

    /**
     * Kebalikannya untuk WO manual: tidak ada damaged_items, jadi penutupan adalah
     * SATU-SATUNYA kesempatan sistem mendapatkan angkanya. Dibiarkan kosong, WO tertutup
     * dengan biaya nol dan anggaran tahun berjalan kehilangan jejaknya.
     */
    public function test_penutupan_wo_manual_mewajibkan_biaya_diketik(): void
    {
        $workOrder = $this->terbitkanManual();
        $workOrder->update(['repair_status' => 'submitted']);

        $this->postReview($workOrder, ['outcome' => 'Diterima'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cost_amount']);

        $this->postReview($workOrder, ['outcome' => 'Diterima', 'cost_amount' => 750000])->assertOk();
        $this->assertSame(750000.0, (float) $workOrder->fresh()->cost_amount);
    }

    /**
     * Arsip Unit = repair_status completed, disaring bulan TERBIT (created_at). WO yang
     * baru diajukan selesai belum arsip — masih bisa dikembalikan "Dikerjakan Ulang".
     */
    public function test_arsip_wo_disaring_bulan_terbit(): void
    {
        $terbit = function (string $kapan, bool $diterima): WorkOrder {
            Carbon::setTestNow($kapan);
            $workOrder = $this->terbitkanManual();
            $workOrder->update(['repair_status' => 'submitted']);
            if ($diterima) {
                $this->postReview($workOrder, ['outcome' => 'Diterima', 'cost_amount' => 500000])->assertOk();
            }

            return $workOrder;
        };

        $arsipSep = $terbit('2026-09-30 23:30:00', true);
        $terbit('2026-10-01 00:00:00', true);
        $terbit('2026-09-15 09:00:00', false);
        Carbon::setTestNow();

        $this->actingAs($this->unit, 'sanctum')
            ->getJson('/api/v1/work-orders?repair_status=completed&month=2026-09')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $arsipSep->id);

        $this->actingAs($this->unit, 'sanctum')
            ->getJson('/api/v1/work-orders?month=september')
            ->assertStatus(422);
    }

    /**
     * Tab "Dalam Pengerjaan" = preparing + in_progress sekaligus (termasuk yang
     * dikembalikan "Dikerjakan Ulang", karena statusnya kembali in_progress). Satu
     * nilai seperti dulu tetap diterima.
     */
    public function test_filter_status_menerima_satu_atau_banyak_nilai(): void
    {
        $baru = $this->terbitkanManual();
        $persiapan = $this->terbitkanManual();
        $persiapan->update(['repair_status' => 'preparing']);
        $dikerjakan = $this->terbitkanManual();
        $dikerjakan->update(['repair_status' => 'in_progress']);
        $this->terbitkanManual()->update(['repair_status' => 'submitted']);

        $ids = fn (string $query) => collect($this->actingAs($this->unit, 'sanctum')
            ->getJson("/api/v1/work-orders?{$query}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$persiapan->id, $dikerjakan->id], $ids('repair_status[]=preparing&repair_status[]=in_progress'));
        $this->assertSame([$baru->id], $ids('repair_status=new'));

        $this->actingAs($this->unit, 'sanctum')
            ->getJson('/api/v1/work-orders?repair_status[]=in_progress&repair_status[]=ngawur')
            ->assertStatus(422);
    }

    /**
     * Penutupan WO inilah yang menutup lingkaran — tanpanya kerusakan tercatat menggantung
     * selamanya di antrean.
     */
    public function test_laporan_jadi_closed_setelah_wo_terakhirnya_diterima(): void
    {
        $report = $this->laporanTervalidasi();
        $this->assertSame('verified', $report->fresh()->report_status);

        $workOrder = $this->siapDievaluasi($report);
        $this->postReview($workOrder, ['outcome' => 'Diterima'])
            ->assertOk()
            ->assertJsonPath('data.report.report_status', 'closed');

        $this->assertDatabaseHas('activity_logs', [
            'auditable_type' => 'incident_report',
            'auditable_id' => $report->uuid,
            'action' => 'report_closed',
        ]);
    }

    /**
     * Laporan dengan dua titik pekerjaan TIDAK tertutup saat yang pertama selesai.
     * Tanpa penjaga ini, laporan berstatus `closed` sambil masih punya WO berjalan — dan
     * pekerjaan itu yang lalu hilang dari antrean siapa pun.
     */
    public function test_laporan_belum_closed_kalau_masih_ada_kerusakan_tanpa_wo(): void
    {
        $report = $this->laporanTervalidasi(['damaged_items' => $this->items(2)]);
        $workOrder = $this->siapDievaluasi($report);

        // Satu baris dilepas lagi — keadaan yang muncul kalau MCS menolak lalu petugas
        // menambahkan kerusakan yang terlewat setelah WO pertama terbit.
        $report->damagedItems()->orderByDesc('id')->limit(1)->update(['work_order_id' => null]);

        $this->postReview($workOrder, ['outcome' => 'Diterima'])->assertOk();

        $this->assertSame('verified', $report->fresh()->report_status);
    }

    /**
     * Arm kedua dari penjaga yang sama: kerusakannya sudah ber-WO semua, tapi salah satu
     * WO-nya masih berjalan. Ditutup di sini, laporan berstatus `closed` sambil masih
     * punya pekerjaan terbuka — dan pekerjaan itu yang lalu hilang dari antrean siapa pun.
     */
    public function test_laporan_belum_closed_kalau_masih_ada_wo_yang_berjalan(): void
    {
        $report = $this->laporanTervalidasi(['damaged_items' => $this->items(2)]);
        $pertama = $this->siapDievaluasi($report);

        $report->damagedItems()->orderByDesc('id')->limit(1)->update(['work_order_id' => null]);
        $kedua = $this->terbitkanDariInsiden($report);

        $this->postReview($pertama, ['outcome' => 'Diterima'])->assertOk();

        $this->assertSame('new', $kedua->fresh()->repair_status);
        $this->assertSame('verified', $report->fresh()->report_status);
    }

    public function test_penutupan_wo_manual_tidak_menyentuh_laporan_mana_pun(): void
    {
        $report = $this->laporanTervalidasi();
        $workOrder = $this->terbitkanManual();
        $workOrder->update(['repair_status' => 'submitted']);

        $this->postReview($workOrder, ['outcome' => 'Diterima', 'cost_amount' => 1])
            ->assertOk()
            ->assertJsonPath('data.report', null);

        $this->assertSame('verified', $report->fresh()->report_status);
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

    /** Laporan yang KEDUA Berita Acaranya sudah disahkan — inilah pemicu antrean. */
    private function laporanTervalidasi(array $override = []): IncidentReport
    {
        $report = $this->laporan($override);
        $this->sahkanBaKejadian($report);
        app(IncidentVerificationService::class)->validateDamageBa($report, $this->mcs);

        return $report;
    }

    private function terbitkanDariInsiden(?IncidentReport $report = null, ?array $assignees = null): WorkOrder
    {
        return app(WorkOrderService::class)->issue($this->unit, [
            'trigger_source' => 'Insiden',
            'incident_report_id' => ($report ?? $this->laporanTervalidasi())->uuid,
            'priority' => 'Tinggi',
            'target_date' => Carbon::tomorrow()->toDateString(),
            'assignee_ids' => $assignees ?? [$this->crew[0]->id],
        ]);
    }

    private function terbitkanManual(): WorkOrder
    {
        return app(WorkOrderService::class)->issue($this->unit, $this->manualPayload());
    }

    private function manualPayload(array $override = []): array
    {
        return $override + [
            'trigger_source' => 'Temuan Patroli',
            'asset_catalog_id' => $this->catalog->id,
            'damage_description' => 'Lampu PJU KM 45+200 mati.',
            'location_type' => 'Jalur Utama',
            'km' => 45, 'm' => 200, 'route' => 'B', 'lane' => 'Bahu Jalan',
            'priority' => 'Sedang',
            'target_date' => Carbon::tomorrow()->toDateString(),
            'assignee_ids' => [$this->crew[0]->id],
        ];
    }

    /**
     * WO yang regunya sudah mengajukan selesai. Statusnya ditulis tangan karena Fase 5
     * (aplikasi Petugas Maintenance) belum ada — begitu ada, ganti dengan panggilan ke
     * service-nya, jangan biarkan dua penulis untuk satu kolom.
     */
    private function siapDievaluasi(?IncidentReport $report = null): WorkOrder
    {
        $workOrder = $this->terbitkanDariInsiden($report);
        $workOrder->update(['repair_status' => 'submitted', 'received_by' => $this->crew[0]->id]);

        return $workOrder;
    }

    private function postWo(array $override = [])
    {
        return $this->actingAs($this->unit, 'sanctum')->postJson('/api/v1/work-orders', $override + [
            'trigger_source' => 'Insiden',
            'priority' => 'Tinggi',
            'target_date' => Carbon::tomorrow()->toDateString(),
            'assignee_ids' => [$this->crew[0]->id],
        ]);
    }

    private function postReview(WorkOrder $workOrder, array $override = [])
    {
        return $this->actingAs($this->unit, 'sanctum')
            ->postJson("/api/v1/work-orders/{$workOrder->id}/review", $override + [
                'meets_quality_standard' => true,
                'quantity_matches_log' => true,
            ]);
    }
}
