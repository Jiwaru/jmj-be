<?php

namespace Tests\Feature;

use App\Models\AssetType;
use App\Models\CatalogPrice;
use App\Models\Culprit;
use App\Models\DamageBa;
use App\Models\DamagedItem;
use App\Models\Document;
use App\Models\IncidentBa;
use App\Models\IncidentReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderLog;
use App\Models\WorkOrderReview;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Seeder itu data, dan data yang salah gagalnya senyap. Yang dijaga di sini cuma
 * satu: katalog hasil seed menghasilkan angka yang sama dengan Lampiran I SK
 * 40/KPTS-JMJ/2025 — Rp 7.362.665 untuk contoh Alur 1.5. Kalau ada yang
 * mengutak-atik harga seed, test ini yang jatuh duluan.
 */
class SeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // WAJIB SEBELUM seed(), dan bukan kerapian: seeder menerbitkan Berita Acara
        // lewat IncidentVerificationService, yang membekukan PDF-nya ke disk 'local'.
        // Tanpa fake, tiap kali berkas ini jalan 30 BA Kejadian + 20 BA Kerusakan
        // ditulis ke storage/app/private/ba SUNGGUHAN — lalu RefreshDatabase membuang
        // barisnya dan berkasnya tinggal sebagai yatim. Terkumpul 46 MB / ~1.080
        // berkas sebelum ketahuan, dan yang lebih buruk: test menulis ke folder yang
        // sama dengan produksi.
        Storage::fake('local');

        $this->seed(DatabaseSeeder::class);
    }

    public function test_seeded_accounts_can_log_in_and_cover_all_four_roles(): void
    {
        $this->postJson('/api/v1/login', [
            'identity_code' => 'JMTO001',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.user.role', 'field_officer');

        $this->assertEqualsCanonicalizing(
            ['field_officer', 'mcs_supervisor', 'maintenance_unit', 'maintenance_officer'],
            User::distinct()->pluck('role')->all(),
        );
    }

    /**
     * Harga hasil seed harus mereproduksi Lampiran I persis, bukan kira-kira.
     *
     * Kolom MAKSIMAL Lampiran I no. 33 dikali kuantitas Alur 1.5, tiap baris dibulatkan
     * ke rupiah utuh sebelum dijumlah — persis seperti yang tercetak di kolom JUMLAH
     * HARGA formulir BA:
     *
     *   2 × 2.105.079,00   = 4.210.158
     *   3 ×   630.906,00   = 1.892.718
     *   3 ×   356.029,50   = 1.068.089  (1.068.088,50 dibulatkan)
     *  12 ×    15.975,00   =   191.700
     *                        ─────────
     *                          7.362.665
     *
     * Dua hal yang MENGGANTIKAN angka lama, dan dua-duanya keputusan 3 Sep:
     *
     * 1. Rp 6.913.300 (dikunci test ini sampai 3 Sep) itu kolom MINIMAL, dan seeder
     *    waktu itu menyimpannya ke `max_price`. Seluruh rupiah di sistem kurang 6,5%.
     *    Contoh di Alur 1.6 yang ditulis ulang, bukan test ini yang dilonggarkan.
     * 2. Rp 7.362.664,50 (angka antara, beberapa jam saja) berakhir ,50 karena MAKSIMAL
     *    Lampiran I berakhir ,50 — sementara `terbilang()` membulatkan. Angka dan huruf
     *    di BA jadi beda 50 sen. Rupiah tidak mengenal sen, jadi subtotal dibulatkan
     *    di sumbernya.
     */
    public function test_guardrail_from_the_seeded_catalog_totals_7_362_665(): void
    {
        $officer = User::where('identity_code', 'JMTO001')->firstOrFail();
        $uuid = (string) Str::uuid();

        // 4 komponen Guardrail Tipe A, kuantitas persis seperti di Alur 1.5.
        $quantities = [
            'Guard Rail Beam (4000X312X2.7)' => 2,
            'Guard Rail Post (1800X4,5 mm)' => 3,
            'Guard Rail, Blocking Piece (350X4,5 mm)' => 3,
            'Mur Baut' => 12,
        ];

        // DISARING per komponen, bukan per jenis: jenis Guardrail juga memuat bagian
        // ujung dan bull nose, dan dua-duanya tidak ikut contoh Alur 1.5.
        $items = CatalogPrice::with('assetCatalog')->whereNull('effective_to')
            ->whereHas('assetCatalog', fn ($q) => $q->whereIn('component', array_keys($quantities)))
            ->get()
            ->map(fn (CatalogPrice $p) => [
                'asset_catalog_id' => $p->asset_catalog_id,
                'catalog_price_id' => $p->id,
                'quantity' => $quantities[$p->assetCatalog->component],
                'damage_percentage' => 100,
            ])->all();

        $this->assertCount(4, $items, 'Seeder harus menghasilkan tepat 4 komponen guardrail.');

        $this->actingAs($officer, 'sanctum')
            ->putJson("/api/v1/incident-reports/{$uuid}", [
                'date_time' => '2026-08-21 14:20:00',
                'km' => 12,
                'm' => 300,
                'route' => 'A',
                'lane' => 'Lajur 1',
                'weather_condition' => 'Cerah',
                'causer' => 'Pecah Ban',
                'analyze' => 'Rusak Berat',
                'is_identified' => false,
                'officer_2_id' => User::where('identity_code', 'JMTO002')->firstOrFail()->id,
                'team' => [$officer->id],
                'damaged_items' => $items,
            ])->assertOk();

        $this->assertEquals('7362665.00', IncidentReport::find($uuid)->total_estimation);
    }

    /**
     * `price` = `max_price`, keputusan Rafiq 3 Sep, MEMBATALKAN "price sengaja NULL"
     * yang dijaga test ini sebelumnya. Alasan NULL-nya sudah habis: 6,5% ternyata
     * inflasi (sheet "Detail" berkas Excel Lampiran I), MAKSIMAL adalah harga kontrak
     * 2020 setelah inflasi, jadi menagih MAKSIMAL = menagih nilai hari ini.
     *
     * Yang dijaga sekarang KESAMAANNYA. Perhitungan membaca `max_price`, layar dan
     * bundel luring membawa `price` juga; dua kolom berisi angka yang sama berarti
     * revisi harga yang cuma menyentuh salah satunya melenceng tanpa error. Kalau
     * suatu saat memang harus beda, test ini yang dihapus dengan sadar.
     */
    public function test_price_column_mirrors_max_price(): void
    {
        $this->assertSame(0, CatalogPrice::whereNull('max_price')->count());
        $this->assertSame(0, CatalogPrice::whereNull('price')->count());
        $this->assertSame(
            0,
            CatalogPrice::whereColumn('price', '!=', 'max_price')->count(),
            'Ada harga dengan price != max_price — satu di antaranya direvisi sendirian.',
        );
    }

    /**
     * TIDAK ADA SEN di seluruh data seed. Rupiah Indonesia tidak mengenal sen, dan
     * `IncidentVerificationService::terbilang()` membulatkan — jadi satu subtotal
     * berkoma cukup untuk membuat ANGKA dan HURUF di BA Kerusakan berbeda, di dokumen
     * bertanda tangan yang jadi dasar tagihan.
     *
     * Bahannya nyata, bukan teoretis: MAKSIMAL Lampiran I banyak yang berakhir ,50
     * (356.029,50 · 1.937.128,50 · 2.119.243,50 …), jadi pembulatan yang dicopot dari
     * `price()` langsung menghasilkan sen lagi di seed berikutnya.
     */
    public function test_no_seeded_money_carries_cents(): void
    {
        foreach ([
            ['damaged_items', 'subtotal'],
            ['incident_report', 'total_estimation'],
            ['incident_report', 'additional_cost'],
            ['damage_ba', 'total_amount'],
            ['work_orders', 'cost_amount'],
        ] as [$tabel, $kolom]) {
            $this->assertSame(
                0,
                DB::table($tabel)->whereRaw("{$kolom} <> trunc({$kolom})")->count(),
                "{$tabel}.{$kolom} punya baris bersen — pembulatan di hulu dicopot.",
            );
        }
    }

    /**
     * MAKSIMAL harus lebih besar dari MINIMAL — dua-duanya disalin dari Lampiran I dan
     * bukan dihitung. Penjaga yang paling murah untuk cacat yang baru saja ditutup:
     * seeder yang keliru menaruh MINIMAL di `max_price` jatuh persis di sini, karena
     * `min_price` yang dikarangnya selalu LEBIH KECIL dari MAKSIMAL yang sebenarnya.
     */
    public function test_seeded_prices_are_copied_from_the_sk_not_derived_from_each_other(): void
    {
        $this->assertSame(
            0,
            CatalogPrice::whereColumn('max_price', '<=', 'min_price')->count(),
            'Ada harga dengan MAKSIMAL <= MINIMAL — Lampiran I tidak pernah begitu.',
        );

        // Guard Rail Beam, satu-satunya baris yang angkanya juga tercetak di Alur 1.5.
        $beam = CatalogPrice::whereHas(
            'assetCatalog',
            fn ($q) => $q->where('component', 'Guard Rail Beam (4000X312X2.7)'),
        )->firstOrFail();

        $this->assertEquals('1976600.00', $beam->min_price, 'MINIMAL Lampiran I no. 33.');
        $this->assertEquals('2105079.00', $beam->max_price, 'MAKSIMAL Lampiran I no. 33.');
    }

    /**
     * Tap pertama layar pemilihan aset 3-tap membaca `asset_types.category` — tanpa
     * query, langsung dari kolomnya (OfflineBundleService). Katalog satu kategori
     * berarti tap itu tidak pernah punya pilihan, dan layarnya terlihat benar di
     * pengembangan justru karena datanya tidak pernah bercabang.
     */
    public function test_the_seeded_catalog_gives_the_three_tap_picker_more_than_one_branch(): void
    {
        $this->assertGreaterThan(
            1,
            AssetType::distinct()->count('category'),
            'Katalog cuma punya satu kategori — tap pertama pemilihan aset tidak teruji.',
        );
    }

    /**
     * Seeder insiden itu fixture tim FE. Yang dijaga di sini bukan jumlah barisnya,
     * tapi apakah tiap CABANG yang harus dirender FE masih punya contohnya — kalau
     * ada yang merampingkan seeder, satu layar FE kehilangan datanya diam-diam dan
     * gejalanya cuma "kok kosong".
     */
    public function test_the_seeded_reports_still_cover_every_branch_the_ui_has_to_render(): void
    {
        $bagus = User::where('identity_code', 'JMTO001')->firstOrFail();

        foreach (['pending_verification', 'rejected', 'verified', 'closed'] as $status) {
            $this->assertTrue(
                IncidentReport::where('report_status', $status)->exists(),
                "Tidak ada laporan contoh ber-status {$status}.",
            );
        }

        $this->assertTrue(
            IncidentBa::where('validation_status', 'rejected')->whereNotNull('rejection_reason')->exists(),
            'Tidak ada alasan penolakan — layar "kenapa laporanku ditolak" tidak bisa diuji.',
        );

        $this->assertTrue(
            IncidentReport::whereNotNull('report_number')->exists(),
            'Tidak ada laporan bernomor — label daftar untuk laporan yang sudah disahkan tidak teruji.',
        );

        // Tabrak lari: kendaraannya terekam, pelakunya tidak ada.
        $this->assertTrue(
            IncidentReport::where('is_identified', false)->whereHas('involvedVehicles')->doesntHave('culprits')->exists(),
            'Tidak ada contoh tabrak lari.',
        );

        // Tidak ada kerusakan aset: is_asset_broken diturunkan server jadi false.
        $this->assertTrue(
            IncidentReport::where('analyze', 'Tidak Ada Kerusakan Aset')
                ->where('is_asset_broken', false)->where('total_estimation', 0)->exists(),
            'Tidak ada contoh laporan tanpa kerusakan aset.',
        );

        $this->assertTrue(IncidentReport::whereNotNull('causer_other')->exists(), 'Tidak ada contoh causer "Lainnya".');
        $this->assertTrue(IncidentReport::where('relief_requested', true)->exists(), 'Tidak ada contoh pengajuan keringanan.');

        // Kartu "Pihak N" punya dua bentuk: yang mengemudi, dan yang cuma pemilik.
        $this->assertTrue(Culprit::whereNotNull('involved_vehicle_id')->exists(), 'Tidak ada pelaku yang menunjuk kendaraannya.');
        $this->assertTrue(Culprit::whereNull('involved_vehicle_id')->exists(), 'Tidak ada pelaku tanpa kendaraan.');

        // Dua kolom yang lahir bareng generator PDF — slot "pemilik / pengemudi /
        // pengurus" dan slot "Ramp ......" di formulir SK. Tanpa contohnya, dua slot
        // itu tidak pernah terisi di data seed dan tidak ada yang tahu kalau
        // pencetakannya patah.
        $this->assertTrue(Culprit::whereNotNull('party_role')->exists(), 'Tidak ada contoh party_role.');
        $this->assertTrue(
            IncidentReport::where('location_type', '!=', 'Jalur Utama')->exists(),
            'Tidak ada contoh kejadian di ramp — slot Ramp di kedua PDF tidak pernah terisi.',
        );

        // ---- Tiga cabang yang HANYA ada karena pengesahan BA dipisah per BA.
        //      Kalau suatu saat digabung lagi jadi satu aksi, ketiganya mustahil
        //      dibuat dan test ini yang jatuh duluan.

        $this->assertTrue(
            IncidentBa::where('validation_status', 'validated')
                ->whereDoesntHave('damageBa')
                ->whereHas('incidentReport', fn ($q) => $q->where('is_asset_broken', true))
                ->exists(),
            'Tidak ada contoh BA Kejadian sudah sah tapi BA Kerusakan masih menunggu — itu isi tab 2 layar MCS.',
        );

        $this->assertTrue(
            DamageBa::where('validation_status', 'rejected')
                ->whereHas('incidentBa', fn ($q) => $q->where('validation_status', 'validated'))
                ->exists(),
            'Tidak ada contoh BA Kerusakan ditolak sementara BA Kejadiannya tetap sah.',
        );

        $this->assertTrue(
            DamageBa::where('validation_status', 'validated')->exists(),
            'Tidak ada BA Kerusakan yang disahkan — tab BA Kerusakan tidak punya bahan.',
        );

        // Nomornya lahir dari counter yang sama dengan produksi, bukan dikarang seeder.
        foreach ([
            IncidentReport::whereNotNull('report_number')->value('report_number'),
            IncidentBa::value('incident_ba_number'),
            DamageBa::value('damage_ba_number'),
        ] as $nomor) {
            $this->assertMatchesRegularExpression('#^\d{3}/TM\.JOGLO/[IVX]+/\d{4}$#', (string) $nomor);
        }

        // Laporan milik petugas lain — sumber 403 yang bisa dicoba dari Postman.
        $this->assertTrue(
            IncidentReport::where('user_id', '!=', $bagus->id)->exists(),
            'Semua laporan milik satu petugas — 403 "laporan orang lain" tidak bisa dicoba manual.',
        );

        // Lebih dari satu halaman, kalau tidak meta.last_page selalu 1.
        $this->actingAs($bagus, 'sanctum')->getJson('/api/v1/incident-reports')
            ->assertOk()->assertJsonPath('meta.last_page', 2);
    }

    /**
     * Cabang Work Order yang harus dirender layar Unit Maintenance.
     *
     * Terpisah dari test di atas dan memang harus: yang di atas menjaga Fase 0-2, yang ini
     * Fase 4-6. Digabung, pesan gagalnya tidak lagi menunjukkan fase mana yang seedernya
     * bolong.
     */
    public function test_the_seeded_work_orders_still_cover_every_branch_the_ui_has_to_render(): void
    {
        foreach (['new', 'in_progress', 'submitted', 'completed'] as $status) {
            $this->assertTrue(
                WorkOrder::where('repair_status', $status)->exists(),
                "Tidak ada Work Order contoh ber-status {$status}.",
            );
        }

        // Akun yang dipakai tim FE untuk mencoba aplikasi Petugas Maintenance harus punya
        // SEMUA status — termasuk yang pernah dikembalikan lalu dikerjakan ulang.
        $slamet = User::where('identity_code', 'JMTM001')->firstOrFail();
        foreach (['new', 'preparing', 'in_progress', 'submitted', 'completed'] as $status) {
            $this->assertTrue(
                WorkOrder::visibleTo($slamet)->where('repair_status', $status)->exists(),
                "JMTM001 tidak punya Work Order contoh ber-status {$status}.",
            );
        }
        $this->assertTrue(
            WorkOrder::visibleTo($slamet)->where('repair_status', 'in_progress')->has('reviews')->exists(),
            'JMTM001 tidak punya contoh pekerjaan yang dikembalikan lalu dikerjakan ulang.',
        );

        // Kartu "Rujukan Laporan Patroli" di form penerbitan WO tidak boleh selalu kosong.
        $hendra = User::where('identity_code', 'JMJ001')->firstOrFail();
        $this->actingAs($hendra, 'sanctum')->getJson('/api/v1/maintenance/patrol-queue')
            ->assertOk()->assertJsonCount(4, 'data');

        // Koordinator wajib (keputusan PO 5 Okt): setiap WO contoh punya TEPAT satu.
        // Yang menerima tugas memang koordinatornya — kecuali kalau perannya sudah dioper
        // lewat oper shift (WO D), dan contoh itu sengaja ada.
        foreach (WorkOrder::with('operationalTeam', 'logs')->get() as $wo) {
            $this->assertSame(1, $wo->operationalTeam->where('is_coordinator', true)->count(),
                "WO {$wo->reference_number}: koordinatornya tidak tepat satu.");

            $oper = $wo->logs->where('entry_type', 'shift_handover')->whereNotNull('handover_to_id')->last();
            $this->assertSame(
                $oper?->handover_to_id ?? $wo->received_by ?? $wo->coordinatorId(),
                $wo->coordinatorId(),
                "WO {$wo->reference_number}: koordinator bukan yang menerima tugas / penerima oper shift.",
            );
        }

        // ANTREANNYA TIDAK BOLEH HABIS. Layar pertama Unit Maintenance justru antrean, dan
        // seeder yang menerbitkan WO untuk ketiga laporan tervalidasi membuat layar itu
        // tidak pernah terlihat berisi — sekaligus membuat POST /work-orders tidak bisa
        // dicoba dari Postman tanpa menyiapkan laporan sendiri lebih dulu.
        $this->assertTrue(
            DamagedItem::whereNull('work_order_id')
                ->whereHas('incidentReport.incidentBa.damageBa', fn ($q) => $q->where('validation_status', 'validated'))
                ->exists(),
            'Antrean pekerjaan kosong — seeder menghabiskan seluruh kerusakan tervalidasi.',
        );

        // Dua pemicu, dua bentuk yang berlawanan isinya.
        $this->assertTrue(
            WorkOrder::where('trigger_source', WorkOrder::TRIGGER_INSIDEN)
                ->whereNotNull('damage_minutes_id')->whereNull('asset_catalog_id')->has('damagedItems')->exists(),
            'Tidak ada contoh WO dari insiden.',
        );
        $this->assertTrue(
            WorkOrder::where('trigger_source', '!=', WorkOrder::TRIGGER_INSIDEN)
                ->whereNull('incident_report_id')->whereNotNull('asset_catalog_id')->exists(),
            'Tidak ada contoh WO manual — cabang temuan patroli / preventif tidak teruji.',
        );

        // Regu lebih dari satu orang: daftar pelaksana yang cuma pernah berisi satu nama
        // tidak pernah membuktikan operational_team memang jamak.
        $this->assertTrue(
            WorkOrder::has('operationalTeam', '>', 1)->exists(),
            'Tidak ada Work Order dengan regu lebih dari satu orang.',
        );

        // Riwayat evaluasi berulang — buktinya baris menumpuk, bukan ditimpa.
        $this->assertTrue(
            WorkOrderReview::where('outcome', 'Dikerjakan Ulang')->whereNotNull('rejection_notes')->exists(),
            'Tidak ada contoh pekerjaan yang dikembalikan ke regu.',
        );
        $this->assertTrue(
            WorkOrderReview::where('outcome', 'Diterima')->exists(),
            'Tidak ada contoh evaluasi diterima.',
        );

        // Kartu "Lewat Target" harus pernah terlihat bukan nol.
        $this->assertTrue(
            WorkOrder::whereIn('repair_status', WorkOrder::OPEN_STATUSES)
                ->whereDate('target_date', '<', now()->toDateString())->exists(),
            'Tidak ada Work Order yang lewat target — kartu keempat dashboard selalu nol.',
        );

        // Log Before-During-After: bahan layar evaluasi Fase 6.
        $this->assertTrue(
            WorkOrderLog::whereIn('bda_stage', ['before', 'during', 'after'])->exists(),
            'Tidak ada log BDA — layar evaluasi tidak punya bukti untuk diperiksa.',
        );

        // Nomor WO berformat SENDIRI, dan bedanya dengan nomor BA cuma sisipan JMTM. Kalau
        // suatu saat keduanya kembali sama, perintah kerja dan Berita Acara tidak bisa
        // dibedakan di kertas yang dipegang regu.
        $this->assertMatchesRegularExpression(
            '#^\d{3}/JMTM/TM\.JOGLO/[IVX]+/\d{4}$#',
            (string) WorkOrder::value('reference_number'),
        );

        // Laporan yang ditutup lewat penutupan WO, bukan lewat UPDATE di seeder.
        $this->assertTrue(
            IncidentReport::where('report_status', 'closed')
                ->whereHas('workOrders', fn ($q) => $q->where('repair_status', 'completed'))->exists(),
            'Ada laporan closed tanpa Work Order yang menutupnya — statusnya ditulis tangan lagi.',
        );
    }

    /** Baris dokumen tanpa berkasnya di disk = 404, dan FE mengira endpoint-nya rusak. */
    public function test_every_seeded_document_has_a_real_file_behind_it(): void
    {
        $documents = Document::all();

        $this->assertNotEmpty($documents, 'Tidak ada dokumen contoh sama sekali.');

        foreach ($documents as $document) {
            $this->assertTrue(
                Storage::disk('local')->exists($document->path),
                "Baris documents #{$document->id} menunjuk berkas yang tidak ada: {$document->path}",
            );
        }

        $owner = User::where('identity_code', 'JMTO001')->firstOrFail();

        // Dokumen MILIK $owner, dipilih pasti — bukan `$documents->first()`: tanpa ORDER BY
        // urutan baris ikut urutan fisik tabel, dan begitu bergeser yang terpilih dokumen
        // orang lain → 403.
        $miliknya = Document::where('documentable_type', 'incident_report')
            ->whereIn('documentable_id', IncidentReport::where('user_id', $owner->id)->pluck('uuid'))
            ->orderBy('id')->firstOrFail();

        $this->actingAs($owner, 'sanctum')
            ->get('/api/v1/documents/'.$miliknya->id)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }
}
