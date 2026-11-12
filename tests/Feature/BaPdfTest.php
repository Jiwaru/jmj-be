<?php

namespace Tests\Feature;

use App\Models\AssetCatalog;
use App\Models\AssetType;
use App\Models\CatalogPrice;
use App\Models\DamageBa;
use App\Models\IncidentBa;
use App\Models\IncidentReport;
use App\Models\User;
use App\Services\Mcs\BaPdfService;
use App\Services\Shared\IncidentReportSyncService;
use App\Services\Mcs\IncidentVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Generator PDF dua Berita Acara — formulir SK halaman 18-19.
 *
 * Isinya diuji lewat HTML-nya (BaPdfService::html), bukan lewat byte PDF: di dalam
 * PDF stream-nya terkompresi dan yang terbaca cuma sampah biner. Yang diuji sebagai
 * PDF betulan cuma yang memang sifat PDF — jumlah halaman, dan bekunya berkas.
 */
class BaPdfTest extends TestCase
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

    private function report(array $override = [], float $percentage = 100): IncidentReport
    {
        return app(IncidentReportSyncService::class)->upsert(
            (string) Str::uuid(),
            $this->officer1,
            $override + [
                'date_time' => '2026-08-21 14:20:00',
                'km' => 12, 'm' => 300, 'route' => 'A', 'lane' => 'Lajur 1',
                'causer' => 'Pecah Ban',
                'analyze' => 'Rusak Berat',
                'is_identified' => true,
                'officer_2_id' => $this->officer2->id,
                'vehicles' => [['vehicle_type' => 'Sedan', 'vehicle_group' => 'Golongan I', 'plate_number' => 'AB 1234 XY']],
                'culprits' => [[
                    'full_name' => 'Rian Hidayat',
                    'party_role' => 'Pengemudi',
                    'vehicle_index' => 0,
                    'national_id' => '3271902805920001',
                    'age' => 34,
                    'occupation' => 'Wiraswasta',
                    'address' => 'Jl. Kaliurang KM 5, Sleman',
                ]],
                'damaged_items' => [[
                    'asset_catalog_id' => $this->price->asset_catalog_id,
                    'catalog_price_id' => $this->price->id,
                    'quantity' => 2,
                    'damage_percentage' => $percentage,
                ]],
            ],
        );
    }

    /** Redesain 16 Sep: penanda tangan sudah ditentukan petugas saat submit. */
    private function sahkan(IncidentReport $report, bool $kerusakan = true): array
    {
        $verify = app(IncidentVerificationService::class);

        $bak = $verify->validateIncidentBa($report, $this->mcs, [
            'duty_period' => 'Shift 2 — 14:00 s.d. 22:00',
        ]);

        return [$bak, $kerusakan ? $verify->validateDamageBa($report->refresh(), $this->mcs) : null];
    }

    /**
     * Isi respons PDF-nya.
     *
     * Dua jalurnya beda tipe dan itu memang disengaja: yang beku distream dari disk
     * (StreamedResponse), yang DRAFT dirender ke memori dan dikirim apa adanya.
     */
    private function isi(TestResponse $response): string
    {
        return $response->baseResponse instanceof StreamedResponse
            ? $response->streamedContent()
            : $response->getContent();
    }

    private function halaman(string $pdf): int
    {
        preg_match_all('#/Type\s*/Page[^s]#', $pdf, $m);

        return count($m[0]);
    }

    // ---- Draft vs beku ---------------------------------------------------------

    public function test_ba_yang_belum_disahkan_dirender_saat_itu_juga_dan_tidak_disimpan(): void
    {
        $report = $this->report();
        $ba = IncidentBa::create([
            'incident_ba_number' => '001/TM.JOGLO/IX/2026',
            'incident_report_id' => $report->uuid,
            'officer_1_id' => $this->officer1->id,
            'officer_2_id' => $this->officer2->id,
        ]);

        $response = $this->actingAs($this->mcs, 'sanctum')
            ->get("/api/v1/incident-bas/{$ba->uuid}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $this->isi($response));
        $this->assertNull($ba->refresh()->pdf_path);
        $this->assertSame([], Storage::disk('local')->allFiles('ba'));

        // Dan pratinjaunya bertanda DRAFT — yang belum disahkan harus kelihatan
        // belum disahkan, kalau tidak orang mencetaknya lalu menekennya.
        $this->assertStringContainsString('DRAFT', app(BaPdfService::class)->html($ba, draft: true));
    }

    // ---- Pratinjau dari uuid laporan (sebelum ACC MCS) --------------------------

    /**
     * Laporan yang belum disentuh MCS belum punya baris BA, jadi endpoint berbasis
     * uuid BA tidak bisa memberinya pratinjau. Yang ini bisa — dan TIDAK boleh
     * melahirkan baris BA (= nomor terbit sebelum ACC).
     */
    public function test_pratinjau_dari_uuid_laporan_jalan_sebelum_acc_tanpa_membuat_ba(): void
    {
        $report = $this->report();
        $this->actingAs($this->mcs, 'sanctum');

        foreach (['incident-ba', 'damage-ba'] as $jenis) {
            $response = $this->get("/api/v1/incident-reports/{$report->uuid}/{$jenis}/pdf")
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $this->isi($response));
        }

        $this->assertSame(0, IncidentBa::count());
        $this->assertSame(0, DamageBa::count());

        $html = app(BaPdfService::class)->html(app(BaPdfService::class)->draftIncidentBa($report), draft: true);
        $this->assertStringContainsString('DRAFT', $html);
        $this->assertStringContainsString($this->officer2->name, $html);
    }

    public function test_pratinjau_dari_uuid_laporan_memberi_berkas_beku_setelah_disahkan(): void
    {
        $report = $this->report();
        [$bak, $bar] = $this->sahkan($report);
        $this->actingAs($this->mcs, 'sanctum');

        $this->assertSame(Storage::disk('local')->get($bak->pdf_path),
            $this->isi($this->get("/api/v1/incident-reports/{$report->uuid}/incident-ba/pdf")));
        $this->assertSame(Storage::disk('local')->get($bar->pdf_path),
            $this->isi($this->get("/api/v1/incident-reports/{$report->uuid}/damage-ba/pdf")));
    }

    /**
     * Baris BA Kerusakan yang ditolak menyimpan total SAAT ditolak. Setelah volumenya
     * dibetulkan, pratinjau harus membaca laporan, bukan baris basi itu.
     */
    public function test_pratinjau_ba_kerusakan_ditolak_memakai_total_terbaru(): void
    {
        $report = $this->report();
        $this->sahkan($report, kerusakan: false);
        $bar = app(IncidentVerificationService::class)->rejectDamageBa($report->refresh(), $this->mcs, [
            'rejection_reason' => 'Volume tidak cocok.',
        ]);

        $report->forceFill(['total_estimation' => 1976600])->save();
        $pdf = app(BaPdfService::class);
        $html = $pdf->html($pdf->draftDamageBa($report->refresh()), draft: true);

        $this->assertStringContainsString($pdf->terbilang(1976600), $html);
        $this->assertStringNotContainsString($bar->amount_in_words, $html);
        // Nomor yang sudah terbit saat ditolak tetap dipakai — template mencetaknya
        // terpecah per potongan kertas, jadi yang dicari nomor urutnya.
        $this->assertStringContainsString(explode('/', $bar->damage_ba_number)[0], $html);
    }

    public function test_pratinjau_ba_kerusakan_ditolak_untuk_laporan_tanpa_kerusakan(): void
    {
        $report = $this->report(['analyze' => 'Tidak Ada Kerusakan Aset', 'damaged_items' => []]);

        $this->actingAs($this->mcs, 'sanctum')
            ->getJson("/api/v1/incident-reports/{$report->uuid}/damage-ba/pdf")
            ->assertStatus(422);
    }

    public function test_pratinjau_dari_uuid_laporan_tetap_dijaga_policy(): void
    {
        $report = $this->report();

        $this->actingAs($this->officer2, 'sanctum')
            ->getJson("/api/v1/incident-reports/{$report->uuid}/incident-ba/pdf")
            ->assertStatus(403);
        $this->actingAs($this->officer1, 'sanctum')
            ->get("/api/v1/incident-reports/{$report->uuid}/incident-ba/pdf")
            ->assertOk();
    }

    public function test_ba_yang_sudah_disahkan_dibekukan_ke_disk_dan_bytenya_tidak_berubah_lagi(): void
    {
        [$bak, $bar] = $this->sahkan($this->report());

        foreach ([$bak, $bar] as $ba) {
            $this->assertNotNull($ba->pdf_path);
            Storage::disk('local')->assertExists($ba->pdf_path);
        }

        $url = "/api/v1/damage-bas/{$bar->uuid}/pdf";
        $pertama = $this->isi($this->actingAs($this->mcs, 'sanctum')->get($url));
        $kedua = $this->isi($this->actingAs($this->mcs, 'sanctum')->get($url));

        // BA yang sudah diteken harus menunjukkan angka yang sama lima tahun kemudian,
        // walaupun katalog harga sudah direvisi dua kali sejak itu.
        $this->assertSame($pertama, $kedua);
        $this->assertSame(Storage::disk('local')->get($bar->pdf_path), $pertama);
    }

    public function test_kedua_ba_muat_satu_halaman(): void
    {
        [$bak, $bar] = $this->sahkan($this->report());

        // Formulir SK-nya satu halaman. Blok tanda tangan yang tumpah ke halaman dua
        // menghasilkan Berita Acara yang lembar keduanya cuma berisi tanda tangan.
        // BA Kerusakan yang paling gampang tumpah: sepuluh baris tabel seperti
        // kertasnya, dan tiap item mendorong sisanya ke bawah.
        foreach ([$bak, $bar] as $ba) {
            $this->assertSame(1, $this->halaman(Storage::disk('local')->get($ba->pdf_path)));
        }
    }

    // ---- Isi ------------------------------------------------------------------

    public function test_nomor_memakai_format_sk_bukan_karangan_desain(): void
    {
        [, $bar] = $this->sahkan($this->report());

        $html = app(BaPdfService::class)->html($bar);

        $this->assertMatchesRegularExpression('#^\d{3}/TM\.JOGLO/[IVX]+/\d{4}$#', $bar->damage_ba_number);
        // Nomornya TIDAK dicari utuh: kertasnya memecahnya jadi empat potong
        // ("......... / TM .JOGLO /......... / 20 ......."), jadi yang dicek potongannya
        // — urutan, bulan Romawi, dan dua digit terakhir tahun — tepat seperti tercetak.
        [$urut, , $bulan, $tahun] = explode('/', $bar->damage_ba_number);
        foreach ([$urut, $bulan, substr($tahun, -2), 'TM .JOGLO'] as $potongan) {
            $this->assertStringContainsString($potongan, $html);
        }
        // 'BAK-2310-00142' dan 'REP-20260824-042' itu karangan desainer Figma.
        $this->assertStringNotContainsString('BAK-', $html);
    }

    public function test_persentase_disebut_hanya_kalau_bukan_seratus(): void
    {
        [, $penuh] = $this->sahkan($this->report());
        // Bentuk berkurung yang dicari, bukan kata "kerusakan" — kata itu ada di judul
        // dokumen, di header tabel, dan di kalimat pembuka.
        $this->assertStringNotContainsString('(kerusakan', app(BaPdfService::class)->html($penuh));

        [, $sebagian] = $this->sahkan($this->report(percentage: 40));

        // Tabel BA tidak punya kolom persentase, jadi tanpa keterangan ini baris 40%
        // terbaca seperti salah hitung: 2 bh x 1.976.600 tidak sama dengan 1.581.280.
        $html = app(BaPdfService::class)->html($sebagian);
        $this->assertStringContainsString('(kerusakan 40%)', $html);
        // Harga satuannya TETAP harga Lampiran I, bukan yang sudah dipotong.
        $this->assertStringContainsString('1.976.600', $html);
    }

    public function test_garis_tanda_tangan_kehilangan_titiknya_begitu_namanya_ada(): void
    {
        [, $bar] = $this->sahkan($this->report());

        $html = app(BaPdfService::class)->html($bar);

        // Nama MCS tercetak DI garisnya, dan garis titik-titiknya hilang — aturan yang
        // sama dengan slot isian: titik-titik itu placeholder.
        $this->assertStringContainsString($this->mcs->name, $html);

        // Yang tersisa bertitik tinggal satu: Deputy AM TM (config kosong). PIHAK
        // KESATU sekarang ikut memuat nama pelakunya.
        $this->assertSame(1, substr_count($html, 'garis-ttd kosong'));
    }

    public function test_slot_jalur_tidak_mengulang_kata_lajur(): void
    {
        [$bak, $bar] = $this->sahkan($this->report(['route' => 'A', 'lane' => 'Lajur 2']));

        foreach ([$bak, $bar] as $ba) {
            $html = strtolower(preg_replace('/\s+/', ' ', app(BaPdfService::class)->html($ba)));

            // Kata "jalur" SUDAH tercetak di kertasnya; 'A' + 'Lajur 2' yang disambung
            // apa adanya berbunyi "jalur a lajur 2".
            $this->assertStringContainsString('jalur 2 arah a', $html);
            $this->assertStringNotContainsString('jalur a lajur', $html);
        }

        // 'Bahu Jalan' tidak berawalan "Lajur" — lewat utuh, tidak ikut dipotong.
        [$bahu] = $this->sahkan($this->report(['route' => 'B', 'lane' => 'Bahu Jalan']));
        $this->assertStringContainsString(
            'jalur bahu jalan arah b',
            strtolower(preg_replace('/\s+/', ' ', app(BaPdfService::class)->html($bahu))),
        );
    }

    public function test_dua_ba_menyebut_ramp_dengan_cara_berbeda(): void
    {
        $rapat = fn ($ba) => strtolower(preg_replace('/\s+/', ' ', app(BaPdfService::class)->html($ba)));

        [$bak, $bar] = $this->sahkan($this->report(['location_type' => 'Ramp Keluar']));

        // BA Kejadian menulis "/ Ramp ......" — satu slot, tidak ada pilihan. Kata
        // "Ramp" sudah tercetak di kertasnya, jadi yang diisi cuma "Keluar".
        $kejadian = $rapat($bak);
        $this->assertStringContainsString('ramp keluar', $kejadian);
        $this->assertStringNotContainsString('ramp ramp keluar', $kejadian);

        // BA Kerusakan menulis "/ on/off ramp ......" — itu PILIH-SATU seperti
        // "pemilik / pengemudi / pengurus" di atasnya: yang terpilih ditebalkan, yang
        // lain tetap tercetak. Slot titik-titiknya dibiarkan kosong karena yang
        // ditanyakan tinggal ramp MANA, dan kolomnya tidak ada.
        $kerusakan = $rapat($bar);
        $this->assertStringContainsString('on/<strong>off</strong> ramp', $kerusakan);
        $this->assertStringNotContainsString('ramp keluar', $kerusakan);

        [$utamaBak, $utamaBar] = $this->sahkan($this->report(['location_type' => 'Jalur Utama']));

        // Jalur utama: slot BA Kejadian kosong ("Ramp Jalur Utama" membaca seolah ada
        // ramp bernama itu), dan di BA Kerusakan tidak ada yang ditebalkan.
        $this->assertStringNotContainsString('jalur utama', $rapat($utamaBak));
        $this->assertStringNotContainsString('<strong>on</strong>', $rapat($utamaBar));
        $this->assertStringNotContainsString('<strong>off</strong>', $rapat($utamaBar));
    }

    public function test_seksi_dikosongkan_kalau_km_di_luar_semua_rentang(): void
    {
        [, $luar] = $this->sahkan($this->report(['km' => 500]));

        $rapat = fn (string $html) => strtolower(preg_replace('/\s+/', ' ', $html));
        $html = $rapat(app(BaPdfService::class)->html($luar));

        // Dikosongkan, BUKAN dibulatkan ke seksi terdekat: seksi yang salah cetak di
        // dokumen tagihan gagalnya senyap.
        $this->assertStringNotContainsString('seksi 1', $html);
        $this->assertStringNotContainsString('seksi 3', $html);

        [, $dalam] = $this->sahkan($this->report(['km' => 12]));
        $isi = $rapat(app(BaPdfService::class)->html($dalam));
        $this->assertStringContainsString('seksi 1', $isi);
        // Kata "Seksi" sudah tercetak di kertasnya — yang diisi cuma angkanya.
        $this->assertStringNotContainsString('seksi seksi', $isi);
    }

    public function test_party_role_yang_terpilih_ditebalkan_sisanya_tetap_tercetak(): void
    {
        [, $bar] = $this->sahkan($this->report());

        $html = app(BaPdfService::class)->html($bar);

        // Kertas aslinya dilingkari, bukan dua yang lain dihapus.
        $this->assertStringContainsString('<strong>pengemudi</strong>', $html);
        $this->assertStringContainsString('pemilik', $html);
        $this->assertStringContainsString('pengurus', $html);
    }

    public function test_deputy_am_tm_kosong_tidak_membuat_pdf_gagal(): void
    {
        config(['toll.deputy_am_tm' => ['name' => null, 'nik' => null]]);

        [$bak] = $this->sahkan($this->report(), kerusakan: false);

        // Baris "Mengetahui" tercetak kosong dan diisi tangan — sama seperti kertas
        // yang dipakai sekarang. Bukan 500.
        Storage::disk('local')->assertExists($bak->pdf_path);
        $this->assertStringContainsString('Deputy Area Manager Traffic Management',
            app(BaPdfService::class)->html($bak));
    }

    public function test_nik_di_ba_itu_nomor_induk_karyawan_bukan_nik_ktp(): void
    {
        [$bak] = $this->sahkan($this->report(), kerusakan: false);

        $html = app(BaPdfService::class)->html($bak);

        // Yang menandatangani BA Kejadian pegawai; identitasnya identity_code.
        $this->assertStringContainsString('JMTO001', $html);
        $this->assertStringContainsString('JMTO002', $html);
        $this->assertStringContainsString('JMTO003', $html);
        // NIK KTP pihak ketiga tidak ada urusannya dengan BA Kejadian.
        $this->assertStringNotContainsString('3271902805920001', $html);
    }

    /**
     * Tabel BA Kerusakan harus BISA DIJUMLAH pembacanya.
     *
     * `total_amount` itu `total_estimation`, yang memuat `additional_cost` — dan biaya
     * itu bukan item Lampiran I, jadi dia tidak punya baris `damaged_items`. Tanpa
     * baris sendiri, kolom JUMLAH HARGA berhenti menjumlah ke TOTAL yang tercetak di
     * bawahnya, dan selisih yang tidak bisa dijelaskan di dokumen tagihan adalah bahan
     * sanggahan pertama yang akan diangkat siapa pun yang keberatan.
     */
    public function test_biaya_tambahan_punya_barisnya_sendiri_supaya_tabelnya_menjumlah(): void
    {
        [, $bar] = $this->sahkan($this->report(['additional_cost' => 500000]));

        $html = app(BaPdfService::class)->html($bar);

        // 2 x 1.976.600 = 3.953.200, ditambah biaya tambahan jadi 4.453.200.
        $this->assertStringContainsString('3.953.200', $html);
        $this->assertStringContainsString('500.000', $html);
        $this->assertStringContainsString('4.453.200', $html);
        $this->assertSame('4453200.00', $bar->total_amount);
    }

    /** Tanpa biaya tambahan, barisnya tidak ikut tercetak. */
    public function test_biaya_tambahan_nol_tidak_menambah_baris(): void
    {
        [, $bar] = $this->sahkan($this->report());

        $this->assertStringNotContainsString('Biaya tambahan', app(BaPdfService::class)->html($bar));
    }

    /**
     * Slot "kendaraan jenis ...... no polisi ......" minta kendaraan yang TERLIBAT,
     * bukan kendaraan yang dikemudikan pelaku. Pemilik yang tidak ikut menyetir
     * tautannya memang null — ERD membolehkannya — dan kalau slot itu ikut kosong,
     * blok PIHAK KESATU tercetak tanpa nomor polisi di dokumen yang dipakai menagih.
     */
    public function test_slot_kendaraan_jatuh_ke_kendaraan_laporan_kalau_pelaku_tidak_menyetir(): void
    {
        [, $bar] = $this->sahkan($this->report([
            'vehicles' => [['vehicle_type' => 'Truk Box', 'vehicle_group' => 'Golongan III', 'plate_number' => 'B 9123 AB']],
            'culprits' => [['full_name' => 'Agus Salim', 'party_role' => 'Pemilik']],
        ]));

        $this->assertNull($bar->incidentBa->incidentReport->culprits->first()->involved_vehicle_id);

        $html = app(BaPdfService::class)->html($bar);
        $this->assertStringContainsString('B 9123 AB', $html);
        $this->assertStringContainsString('Truk Box', $html);
    }

    /**
     * Tabrak lari: blok PIHAK KESATU memang kosong, tapi slot namanya menyebutkan
     * kenapa. BA-nya harus terbit walau pelakunya tidak ada — itu yang memasukkan
     * asetnya ke antrean Work Order — dan blok yang kosong seluruhnya terbaca seperti
     * formulir yang lupa diisi, padahal justru itu faktanya.
     */
    public function test_tabrak_lari_menyebutkan_dirinya_di_slot_nama(): void
    {
        [, $bar] = $this->sahkan($this->report(['is_identified' => false, 'culprits' => []]));

        $html = app(BaPdfService::class)->html($bar);

        $this->assertSame(0, $bar->incidentBa->incidentReport->culprits()->count());
        $this->assertStringContainsString('TIDAK TERIDENTIFIKASI (tabrak lari)', $html);
    }

    /** Yang teridentifikasi tidak ikut kena keterangan itu. */
    public function test_pelaku_teridentifikasi_tidak_ditandai_tabrak_lari(): void
    {
        [, $bar] = $this->sahkan($this->report());

        $html = app(BaPdfService::class)->html($bar);

        $this->assertStringContainsString('Rian Hidayat', $html);
        $this->assertStringNotContainsString('TIDAK TERIDENTIFIKASI', $html);
    }

    // ---- Akses ----------------------------------------------------------------

    /** Dibuka atas permintaan tim FE — pola sama dengan GET /incident-reports/{uuid}. */
    public function test_petugas_lapangan_boleh_mengunduh_pdf_ba_laporannya_sendiri(): void
    {
        [$bak, $bar] = $this->sahkan($this->report());

        $this->actingAs($this->officer1, 'sanctum');
        $this->getJson("/api/v1/incident-bas/{$bak->uuid}/pdf")->assertOk();
        $this->getJson("/api/v1/damage-bas/{$bar->uuid}/pdf")->assertOk();
    }

    /**
     * officer2 cuma penanda tangan 2 (kolom officer_2_id), bukan pemilik laporan
     * (incident_report.user_id) — sama seperti GET /incident-reports/{uuid}, jadi
     * tetap 403 walau namanya tercetak di BA.
     */
    public function test_petugas_lapangan_tidak_boleh_mengunduh_pdf_ba_laporan_orang_lain(): void
    {
        [$bak, $bar] = $this->sahkan($this->report());

        $this->actingAs($this->officer2, 'sanctum');
        $this->getJson("/api/v1/incident-bas/{$bak->uuid}/pdf")->assertStatus(403);
        $this->getJson("/api/v1/damage-bas/{$bar->uuid}/pdf")->assertStatus(403);
    }

    public function test_pdf_tidak_dibungkus_envelope(): void
    {
        [$bak] = $this->sahkan($this->report(), kerusakan: false);

        $body = $this->isi($this->actingAs($this->mcs, 'sanctum')
            ->get("/api/v1/incident-bas/{$bak->uuid}/pdf"));

        // ApiEnvelope melewatkan respons non-JSON tanpa disentuh. Kalau yang datang
        // JSON ber-envelope dari endpoint ini, itu pasti error — bukan berkasnya.
        $this->assertStringStartsWith('%PDF', $body);
    }
}
