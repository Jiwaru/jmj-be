<?php

namespace Database\Seeders;

use App\Models\AssetCatalog;
use App\Models\AssetType;
use App\Models\CatalogPrice;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * asset_types + asset_catalog + catalog_prices sekaligus — satu dataset yang
 * harus seiring. Dipecah tiga file cuma menambah berkas, tidak menambah apa pun:
 * ketiganya selalu jalan berurutan dan tidak pernah dipanggil sendiri-sendiri.
 *
 * SUMBER: Lampiran I SK 40/KPTS-JMJ/2025, halaman dokumen 1-2 (PDF 7-8), dan
 * berkas kerjanya `Daftar Harga Perbaikan r1.xlsx` sheet "Detail". Angkanya
 * DISALIN, bukan dihitung — lihat catatan MINIMAL/MAKSIMAL di bawah.
 *
 * DIKURASI, BUKAN DIIMPOR. Lampiran I punya 388 baris; yang di-seed 25, delapan
 * jenis. Alasannya bukan kemalasan:
 *
 *   1. Tiap baris katalog punya slot foto standar (`asset_types.standard_photo_id`
 *      dan `asset_catalog.standard_photo_id`), dan SELURUH katalog ikut
 *      `GET /sync/bootstrap` ke HP petugas patroli yang bekerja luring. 388 baris
 *      berikut fotonya = bundel yang tidak masuk akal diunduh di bahu jalan.
 *   2. Yang bisa ditabrak pengguna jalan cuma ada di halaman 1-2. Sisanya —
 *      peralatan gardu, CCTV, sentral komunikasi, perabot kantor gerbang — tidak
 *      pernah jadi baris `damaged_items` di sistem ganti rugi ini.
 *
 * Saringannya satu pertanyaan: APA YANG BENAR-BENAR RUSAK WAKTU KENDARAAN
 * MENABRAK. Yang lolos, urut dari yang paling sering: pagar pengaman kedua sisi
 * lajur (guardrail dan concrete barrier), ujung-ujungnya yang kena di tabrakan
 * frontal (bagian ujung, bull nose, crash cushion), rambu, delineator, patok
 * kilometer, dan pagar ROW untuk kendaraan yang lepas jauh dari badan jalan.
 *
 * YANG SENGAJA TIDAK IKUT, dan alasannya masing-masing:
 *   - Rambu Petunjuk (43-48), 81-407 juta. Itu rambu gantry besar; yang ditabrak
 *     mobil rambu tepi jalan, bukan portal di atas lajur.
 *   - Lampu PJU (63-67). Tiang roboh memang nyata, tapi SAT. di Lampiran I
 *     tertulis `m'` untuk "Lampu PJU, Tinggi 13 m" seharga 27.083.100. Kalau `m'`
 *     dibaca apa adanya, satu tiang jadi 13x28,8 juta = 375 juta. Hampir pasti
 *     salah ketik untuk `unit`, tapi "hampir pasti" tidak cukup untuk angka yang
 *     masuk Berita Acara. Ditanyakan dulu ke kantor.
 *   - Marka jalan, kerb, rubber cone, water barrier, tanaman. Rusak karena
 *     dilindas, bukan ditabrak, dan nyaris tidak pernah jadi tagihan sendiri.
 */
class CatalogSeeder extends Seeder
{
    /**
     * MINIMAL dan MAKSIMAL DUA-DUANYA disalin apa adanya dari Lampiran I.
     *
     * Sampai 3 Sep berkas ini menyimpan MINIMAL sebagai `max_price` lalu mengarang
     * `min_price = max / 1,065`. Akibatnya SETIAP angka rupiah di sistem kurang
     * 6,5% dari yang tercetak di SK — subtotal, `total_estimation`, sampai PDF BA
     * Kerusakan. Kelas kegagalan yang paling ditakuti proyek ini: senyap, dan baru
     * ketahuan waktu BA dicocokkan dengan kertas.
     *
     * Arti 6,5%-nya: sheet "Detail" berkas Excel di atas memberi judul kolom
     * `INFLASI 6.5 %`, dengan dua catatan di sebelahnya — "nilai minimal harga
     * kontrak 2020" dan "nilai maksimal sudah dengan nilai inflasi". MINIMAL =
     * harga kontrak 2020, MAKSIMAL = harga itu setelah inflasi.
     *
     * @var array<int, array{category: string, name: string, description: string, items: array<int, array{0: string, 1: string, 2: string, 3: float, 4: float}>}>
     */
    private const KATALOG = [
        [
            // SK Pasal 1 menyebut "guardrail" HURUF PER HURUF di bawah sarana
            // penunjang jalan tol. Judul "BANGUNAN PELENGKAP" di Lampiran I itu
            // pengelompokan biaya, bukan taksonomi aset — yang dipakai Pasal 1.
            'category' => 'support_facility',
            'name' => 'Guardrail Kendaraan Tipe A',
            'description' => 'Pagar pengaman lalu lintas tipe A beserta komponen dan bagian ujungnya.',
            // Lampiran I no. 33 (empat komponen yang dipasang bersama), ditambah
            // no. 34 dan 35 — di SK dua nomor sendiri, tapi dua-duanya bagian dari
            // pemasangan guardrail dan justru bagian yang kena di tabrakan frontal.
            // [name, component, satuan, MINIMAL, MAKSIMAL]
            'items' => [
                ['Guard Rail Beam', 'Guard Rail Beam (4000X312X2.7)', 'bh', 1976600, 2105079],
                ['Guard Rail Post', 'Guard Rail Post (1800X4,5 mm)', 'bh', 592400, 630906],
                ['Blocking Piece', 'Guard Rail, Blocking Piece (350X4,5 mm)', 'bh', 334300, 356029.50],
                ['Mur Baut', 'Mur Baut', 'bh', 15000, 15975],
                ['Bagian Ujung Guardrail', 'Bagian ujung guardrail', 'bh', 492700, 524725.50],
                ['Bull Nose', 'Bull Nose', 'bh', 1551000, 1651815],
            ],
        ],
        [
            // Pasal 1: "pagar pengamanan lalu lintas" ada di bawah perlengkapan
            // jalan tol. Kategori KEDUA, dan itu bukan kebetulan — tap pertama
            // layar pemilihan aset 3-tap membaca `asset_types.category`, jadi
            // dengan satu kategori saja tap itu tidak pernah punya pilihan.
            'category' => 'road_equipment',
            'name' => 'Concrete Barrier',
            'description' => 'Pembatas beton pengaman lalu lintas, dipasang per meter lari.',
            // Lampiran I no. 54 dan 55. Dua-duanya baris bernomor sendiri di SK,
            // dan di sini jadi dua komponen satu jenis: bukan bagian yang dipasang
            // bersama seperti guardrail, tapi dua varian yang dipilih salah satu.
            'items' => [
                ['Concrete Barrier', 'Concrete Barrier, Tipe A (2 Muka)', "m'", 1818900, 1937128.50],
                ['Concrete Barrier', 'Concrete Barrier, Tipe B (1 Muka)', "m'", 1418200, 1510383],
            ],
        ],
        [
            'category' => 'road_equipment',
            'name' => 'Crash Cushion',
            'description' => 'Peredam tabrakan di ujung pemisah jalur (gore area).',
            // Lampiran I no. 37. SK mengejanya "Crash Cussion" — `component`
            // disalin apa adanya supaya masih bisa dicocokkan dengan kertasnya.
            'items' => [
                ['Crash Cushion 4,6 m', 'Crash Cussion 4,6 m', 'unit', 329552700, 350973625.50],
            ],
        ],
        [
            // Pasal 1: "rambu-rambu lalu lintas" di bawah perlengkapan jalan tol.
            'category' => 'road_equipment',
            'name' => 'Rambu Pengaturan dan Peringatan',
            'description' => 'Rambu tepi jalan, lima tipe menurut ukuran dan tiangnya.',
            // Lampiran I no. 38-42. Rambu Petunjuk (43-48) sengaja tidak ikut —
            // itu portal gantry, bukan rambu yang ditabrak mobil.
            'items' => [
                ['Rambu Tipe A-1', 'Rambu Pengaturan dan Peringatan, Tipe A-1', 'bh', 1948200, 2074833],
                ['Rambu Tipe A-2', 'Rambu Pengaturan dan Peringatan, Tipe A-2', 'bh', 4561600, 4858104],
                ['Rambu Tipe B-1', 'Rambu Pengaturan dan Peringatan, Tipe B-1', 'bh', 4038900, 4301428.50],
                ['Rambu Tipe B-2', 'Rambu Pengaturan dan Peringatan, Tipe B-2', 'bh', 5787500, 6163687.50],
                ['Rambu Tipe C-1', 'Rambu Pengaturan dan Peringatan, Tipe C-1', 'bh', 7156800, 7621992],
            ],
        ],
        [
            'category' => 'road_equipment',
            'name' => 'Delineator',
            'description' => 'Pemantul cahaya penanda tepi lajur.',
            // Lampiran I no. 51 dan 52. Paling sering kena, paling murah — 71.000
            // jarang layak diberkasi sendiri, tapi ikut jadi baris kalau kena
            // bersama guardrail dalam satu kejadian.
            'items' => [
                ['Mata Kucing', 'Delineator Tipe B / Mata Kucing', 'bh', 71000, 75615],
                ['Paku Marka', 'Delineator Tipe C / Paku Marka', 'bh', 335800, 357627],
            ],
        ],
        [
            // Pasal 1: "patok-patok ruang milik jalan".
            'category' => 'road_equipment',
            'name' => 'Patok Kilometer',
            'description' => 'Patok penanda kilometer di median.',
            // Lampiran I no. 56.
            'items' => [
                ['Patok Kilometer', 'Patok Kilometer (Tengah)', 'bh', 1989900, 2119243.50],
            ],
        ],
        [
            // Pasal 1: "pagar ruang milik jalan". Dua tipe = dua jenis, bukan satu
            // jenis delapan komponen: "Colom 300.15.15", "Kawat duri", dan "Beton
            // Kelas C" muncul di kedua tipe, dan digabung yang satu akan menimpa
            // yang lain (`updateOrCreate` berkunci asset_type_id + component).
            'category' => 'road_equipment',
            'name' => 'Pagar ROW Tipe 1 (Panel Beton)',
            'description' => 'Pagar batas ruang milik jalan, panel beton.',
            // Lampiran I no. 57. Ejaan "Colom" disalin apa adanya dari SK.
            'items' => [
                ['Fence 240.50.5', 'Fence 240.50.5', 'pcs', 202100, 215236.50],
                ['Colom 300.15.15', 'Colom 300.15.15', 'pcs', 243900, 259753.50],
                ['Kawat Duri', 'Kawat duri', "m'", 11200, 11928],
                ['Besi Siku', 'Besi siku', 'kg', 20900, 22258.50],
                ['Beton Kelas C', 'Beton Kelas C', 'm3', 865300, 921544.50],
            ],
        ],
        [
            'category' => 'road_equipment',
            'name' => 'Pagar ROW Tipe 2 (Kawat Berduri)',
            'description' => 'Pagar batas ruang milik jalan, kawat berduri.',
            // Lampiran I no. 58.
            'items' => [
                ['Colom 300.15.15', 'Colom 300.15.15', 'pcs', 243900, 259753.50],
                ['Kawat Duri', 'Kawat duri', "m'", 11200, 11928],
                ['Beton Kelas C', 'Beton Kelas C', 'm3', 865300, 921544.50],
            ],
        ],
    ];

    public function run(): void
    {
        // Siapa pun yang boleh mengubah katalog. Seeder jalan setelah UserSeeder.
        $changedBy = User::where('role', 'maintenance_unit')->firstOrFail();

        foreach (self::KATALOG as $jenis) {
            $type = AssetType::updateOrCreate(
                ['category' => $jenis['category'], 'name' => $jenis['name']],
                ['description' => $jenis['description']],
            );

            foreach ($jenis['items'] as [$name, $component, $unit, $min, $max]) {
                $catalog = AssetCatalog::updateOrCreate(
                    ['asset_type_id' => $type->id, 'component' => $component],
                    ['name' => $name, 'unit_of_measurement' => $unit],
                );

                CatalogPrice::updateOrCreate(
                    ['asset_catalog_id' => $catalog->id, 'effective_to' => null],
                    [
                        // = max_price, keputusan Rafiq 3 Sep. MAKSIMAL adalah harga
                        // kontrak 2020 SETELAH inflasi 6,5%, jadi menagih MAKSIMAL
                        // berarti menagih nilai hari ini. Perhitungan tetap membaca
                        // `max_price` (keputusan 31 Ags) — kolom ini yang mengikut,
                        // bukan sebaliknya, dan SeederTest menjaga keduanya sama.
                        'price' => $max,
                        'min_price' => $min,
                        'max_price' => $max,
                        // Tanggal SK: 05 November 2025.
                        'effective_from' => '2025-11-05',
                        // CHECK cuma menerima inflation/vendor_adjustment/admin_revision.
                        // 'initial' ditolak, jadi baris pertama pun admin_revision.
                        'change_reason' => 'admin_revision',
                        'changed_by' => $changedBy->id,
                    ],
                );
            }
        }
    }
}
