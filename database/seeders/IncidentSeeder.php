<?php

namespace Database\Seeders;

use App\Models\CatalogPrice;
use App\Models\Document;
use App\Models\IncidentReport;
use App\Models\OperationalTeam;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Shared\IncidentReportSyncService;
use App\Services\Mcs\IncidentVerificationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Laporan insiden contoh — supaya tim FE punya sesuatu untuk dirender.
 *
 * Tanpa ini `GET /incident-reports` mengembalikan daftar kosong, layar detail tidak
 * punya apa pun untuk dibuka, dan layar "kenapa laporanku ditolak" tidak bisa diuji
 * sama sekali: alasannya ada di `incident_ba.rejection_reason`, dan baris itu cuma
 * lahir di Fase 2 yang endpoint-nya belum ada.
 *
 * BARISNYA DIBUAT LEWAT IncidentReportSyncService::upsert(), bukan insert langsung.
 * Jadi subtotal, `total_estimation`, `is_asset_broken`, dan terjemahan `vehicle_index`
 * → `involved_vehicle_id` lahir dari kode yang sama persis dengan yang dipakai HP.
 * Kalau service-nya berubah, data seed ikut berubah — bukan jadi museum aturan lama.
 *
 * uuid-nya sengaja BUKAN acak: laporan ke-N jadi `0000000N-0000-4000-8000-00000000000N`,
 * supaya FE boleh menuliskannya di test dan koleksi Postman.
 *
 * Berhenti di Fase 2. Work Order dan turunannya ada di WorkOrderSeeder yang jalan
 * SESUDAH ini — pemicunya `damage_ba` tervalidasi, jadi urutannya memang tidak bisa
 * dibalik. `activity_logs` tetap tidak di-seed: barisnya lahir dari aksi, dan mengarang
 * jejak aksi yang tidak pernah terjadi persis kebalikan dari gunanya tabel itu.
 */
class IncidentSeeder extends Seeder
{
    /** Laporan pengisi tanpa isi menarik. 7 + 18 = 25 > 20 per halaman. */
    private const FILLER = 18;

    /**
     * Tiga cabang yang HANYA bisa ada karena pengesahan BA dipisah per BA (laporan
     * 26-28). Kalau suatu saat pengesahannya digabung lagi jadi satu aksi, ketiganya
     * mustahil dibuat — dan seeder ini yang jatuh duluan, bukan layar FE.
     */
    private const CABANG_MCS = 3;

    public function run(): void
    {
        $bagus = User::where('identity_code', 'JMTO001')->firstOrFail();
        $rian = User::where('identity_code', 'JMTO002')->firstOrFail();
        $dewi = User::where('identity_code', 'JMTO003')->firstOrFail();

        $sync = app(IncidentReportSyncService::class);
        $verify = app(IncidentVerificationService::class);

        // Dihapus dulu supaya `db:seed` boleh diulang tanpa migrate:fresh. Perlu:
        // upsert() menolak laporan yang sudah 'verified', jadi seeding kedua kali
        // akan mati di laporan #3 kalau barisnya masih ada.
        //
        // operational_team WAJIB dihapus duluan dan terpisah: FK-nya restrictOnDelete,
        // bukan cascade — sengaja, karena satu baris pivot bisa menunjuk work_order
        // dan menghapusnya lewat insiden akan salah. Anak yang lain (culprit,
        // involved_vehicles, damaged_items, incident_ba, damage_ba) memang cascade.
        //
        // Tanpa baris ini seeder mati dengan FK violation di percobaan kedua. Sempat
        // tidak ketahuan karena alur bakunya selalu `migrate:fresh --seed`, jadi
        // tabelnya selalu kosong duluan.
        // Work Order ikut restrictOnDelete ke incident_report, dan itu juga disengaja: WO
        // adalah pekerjaan yang sudah terjadi, bukan turunan laporan yang boleh ikut
        // terhapus. Jadi WO dari laporan-laporan ini pergi duluan, beserta regunya —
        // urutan dan alasannya sama persis dengan operational_team di bawah.
        // (work_order_logs dan work_order_reviews memang cascade dari work_orders.)
        $workOrders = WorkOrder::whereIn('incident_report_id', $this->uuids())->pluck('id');
        OperationalTeam::whereIn('work_order_id', $workOrders)->delete();
        WorkOrder::whereIn('id', $workOrders)->delete();

        OperationalTeam::whereIn('incident_report_id', $this->uuids())->delete();
        IncidentReport::whereIn('uuid', $this->uuids())->delete();

        // ---- 1. Jalur normal lengkap. Dua pihak, empat dokumen, belum disentuh MCS.
        $sync->upsert(self::uuid(1), $bagus, [
            'date_time' => now()->subDay()->setTime(14, 20)->toDateTimeString(),
            'km' => 12, 'm' => 300, 'route' => 'A', 'lane' => 'Lajur 1',
            'weather_condition' => 'Cerah', 'causer' => 'Pecah Ban',
            'incident_explanation' => 'Kendaraan pecah ban lalu menabrak guardrail sepanjang kurang lebih 8 meter.',
            'action' => ['Pemasangan Rambu Darurat (Cone/Barrier)', 'Koordinasi Unit Derek'],
            'analyze' => 'Rusak Berat',
            'conclusion' => 'Terbitkan Work Order Penggantian Aset',
            'is_identified' => true,
            'settlement_type' => 'Tunai / Transfer',
            // Penanda tangan 2 BA Kejadian (redesain 16 Sep) — ditunjuk pelapor
            // sendiri, bebas dari `team`. Di sini kebetulan sama dengan regunya.
            'officer_2_id' => $rian->id,
            'team' => [$bagus->id, $rian->id],
            'vehicles' => [
                ['vehicle_type' => 'Sedan', 'vehicle_group' => 'Golongan I', 'plate_number' => 'AB 1234 XY'],
                ['vehicle_type' => 'Truk Box', 'vehicle_group' => 'Golongan III', 'plate_number' => 'B 9123 AB'],
            ],
            'culprits' => [
                ['full_name' => 'Rian Hidayat', 'vehicle_index' => 0, 'party_role' => 'Pengemudi', 'national_id' => '3271902805920001',
                    'driver_license_number' => '3471010101010001', 'phone_number' => '081234567890',
                    'address' => 'Jl. Kaliurang KM 5, Sleman', 'age' => 34, 'occupation' => 'Wiraswasta'],
                ['full_name' => 'Setiawan Nugroho', 'vehicle_index' => 1, 'party_role' => 'Pengemudi', 'national_id' => '3374112003880002',
                    'driver_license_number' => '3374112003880007', 'phone_number' => '081298765432',
                    'address' => 'Jl. Solo KM 9, Klaten', 'age' => 41, 'occupation' => 'Sopir'],
            ],
            'damaged_items' => $this->guardrail([2, 3, 3, 12]),
        ]);
        $this->photos(self::uuid(1), $bagus, [
            'scene_photo' => 'Foto TKP',
            'vehicle_photo' => 'Foto Kendaraan',
            'id_card_photo' => 'Foto KTP',
            'customer_signature' => 'Tanda Tangan',
        ]);

        // ---- 2. DITOLAK MCS. Satu-satunya cara menguji layar "kenapa ditolak".
        //         Regu TKP cuma 1 orang ($bagus) tapi penanda tangan 2 tetap ada
        //         ($rian, tidak hadir di TKP) — persis skenario yang ditanyakan FE.
        $sync->upsert(self::uuid(2), $bagus, [
            'date_time' => now()->subDays(3)->setTime(9, 5)->toDateTimeString(),
            'km' => 8, 'm' => 750, 'route' => 'B', 'lane' => 'Bahu Jalan',
            'weather_condition' => 'Hujan', 'causer' => 'Kurang Antisipasi / Jaga Jarak',
            'incident_explanation' => 'Kendaraan menyerempet guardrail saat menghindari kendaraan lain.',
            'action' => ['Pengaturan Arus Lalu Lintas (Trafik)'],
            'analyze' => 'Rusak Ringan',
            'conclusion' => 'Terbitkan Work Order Perbaikan Parsial',
            'is_identified' => true,
            'settlement_type' => 'Perbaikan oleh Rekanan',
            'officer_2_id' => $rian->id,
            'team' => [$bagus->id],
            'vehicles' => [['vehicle_type' => 'Minibus/SUV', 'vehicle_group' => 'Golongan I', 'plate_number' => 'AA 8899 CD']],
            'culprits' => [['full_name' => 'Yusuf Maulana', 'vehicle_index' => 0, 'national_id' => '3471051506950003',
                'phone_number' => '085712340000', 'age' => 30]],
            'damaged_items' => $this->guardrail([1, 0, 0, 4]),
        ]);
        $this->photos(self::uuid(2), $bagus, ['scene_photo' => 'Foto TKP (buram)']);
        $verify->rejectIncidentBa($this->report(2), $dewi, [
            'rejection_reason' => 'Foto TKP tidak terbaca dan plat nomor tidak terlihat. Mohon diunggah ulang.',
        ]);

        // ---- 3. DISAHKAN. Bernomor, dan sudah tidak bisa di-PUT lagi (422).
        //         Sekalian: pelaku tanpa kendaraan — pemilik yang tidak ikut mengemudi.
        $sync->upsert(self::uuid(3), $bagus, [
            'date_time' => now()->subDays(6)->setTime(22, 40)->toDateTimeString(),
            'km' => 21, 'm' => 100, 'route' => 'A', 'lane' => 'Lajur 2',
            'weather_condition' => 'Berkabut', 'causer' => 'Mengantuk / Micro-sleep',
            'incident_explanation' => 'Pengemudi mengantuk, kendaraan keluar lajur dan menabrak guardrail.',
            'action' => ['Pemasangan Rambu Darurat (Cone/Barrier)', 'Evakuasi Pengemudi / Korban', 'Koordinasi Kepolisian / Medis'],
            'analyze' => 'Rusak Berat',
            'conclusion' => 'Terbitkan Work Order Penggantian Aset',
            'is_identified' => true,
            'settlement_type' => 'Tunai / Transfer',
            'officer_2_id' => $rian->id,
            'team' => [$bagus->id, $rian->id],
            'vehicles' => [['vehicle_type' => 'Truk Tangki', 'vehicle_group' => 'Golongan IV', 'plate_number' => 'H 4455 KL']],
            'culprits' => [
                ['full_name' => 'Bambang Susilo', 'vehicle_index' => 0, 'party_role' => 'Pengemudi', 'national_id' => '3374010101800004',
                    'driver_license_number' => '3374010101800009', 'phone_number' => '081377778888',
                    'age' => 45, 'occupation' => 'Sopir'],
                // Pemilik kendaraan, tidak ikut mengemudi. vehicle_index sengaja tidak
                // dikirim → involved_vehicle_id NULL, involved_vehicle null di response.
                ['full_name' => 'CV Sumber Rejeki (pemilik)', 'party_role' => 'Pemilik', 'phone_number' => '02748123456',
                    'address' => 'Jl. Magelang KM 7, Sleman', 'occupation' => 'Badan usaha'],
            ],
            'damaged_items' => $this->guardrail([4, 5, 5, 20]),
        ]);
        $verify->validateIncidentBa($this->report(3), $dewi, []);
        $verify->validateDamageBa($this->report(3), $dewi);

        // ---- 4. SELESAI + keringanan. Dropdown 3 baris → 2 kolom, baris ketiganya.
        $sync->upsert(self::uuid(4), $bagus, [
            'date_time' => now()->subDays(12)->setTime(16, 15)->toDateTimeString(),
            'km' => 4, 'm' => 20, 'route' => 'B', 'lane' => 'Lajur 1',
            'weather_condition' => 'Cerah', 'causer' => 'Menghindari Objek / Hewan',
            'incident_explanation' => 'Menghindari hewan yang menyeberang, kendaraan menabrak guardrail.',
            'action' => ['Pembersihan Lajur dari Puing'],
            'analyze' => 'Rusak Ringan', 'conclusion' => 'Selesai',
            'is_identified' => true,
            // "Mengajukan Keringanan" BUKAN nilai ketiga settlement_type — orangnya
            // tetap bayar transfer, nominalnya yang diturunkan (SK Pasal 4 ayat 1).
            'settlement_type' => 'Tunai / Transfer',
            'relief_requested' => true,
            'pledged_amount' => 500000,
            'officer_2_id' => $rian->id,
            'team' => [$bagus->id],
            'vehicles' => [['vehicle_type' => 'Pick-up', 'vehicle_group' => 'Golongan II', 'plate_number' => 'AB 7712 EF']],
            'culprits' => [['full_name' => 'Sri Wahyuni', 'vehicle_index' => 0, 'party_role' => 'Pengemudi', 'national_id' => '3471024207910005',
                'phone_number' => '087811112222', 'age' => 35, 'occupation' => 'Pedagang']],
            'damaged_items' => $this->guardrail([1, 1, 1, 4]),
        ]);
        // Tanpa statement_photo: "surat pernyataan tidak sanggup bayar penuh" yang
        // bermaterai + disahkan kelurahan ITU surat keringanannya, satu kertas
        // (keputusan 2 Okt). statement_photo tidak dipakai UI mana pun.
        $this->photos(self::uuid(4), $bagus, ['relief_letter' => 'Surat Keringanan'] + $this->buktiLapangan(4));
        $verify->validateIncidentBa($this->report(4), $dewi, []);
        $verify->validateDamageBa($this->report(4), $dewi);
        // Laporan ini berakhir 'closed', tapi TIDAK ditulis di sini lagi — WorkOrderSeeder
        // yang menutupnya lewat WorkOrderService::review(), karena sejak Fase 6 ada yang
        // menutup laporan memang penutupan Work Order. Statusnya sekarang lahir dari
        // aturan yang berlaku, bukan dari UPDATE yang meniru hasilnya.

        // ---- 5. TABRAK LARI. Kendaraan terekam, pelakunya tidak ada.
        //         Kerusakannya tetap dicatat dan tetap diperbaiki — cuma tidak ada
        //         yang bisa ditagih. Itu alasan Work Order tidak digantung ke tagihan.
        $sync->upsert(self::uuid(5), $bagus, [
            'date_time' => now()->subHours(20)->toDateTimeString(),
            'km' => 17, 'm' => 640, 'route' => 'A', 'lane' => 'Lajur 3',
            // Satu-satunya contoh bukan jalur utama. Tanpa ini slot "Ramp ......" di
            // kedua PDF tidak pernah terisi di data contoh, dan tidak ada yang tahu
            // kalau pencetakannya patah. Keluar, bukan masuk: pelakunya meninggalkan
            // lokasi.
            'location_type' => 'Ramp Keluar',
            'weather_condition' => 'Hujan', 'causer' => 'Faktor Cuaca (Jalan Licin/Berkabut)',
            'incident_explanation' => 'Guardrail penyok, pelaku meninggalkan lokasi. Plat terekam CCTV gerbang.',
            'action' => ['Pemasangan Rambu Darurat (Cone/Barrier)', 'Pembersihan Lajur dari Puing'],
            'analyze' => 'Rusak Berat',
            'conclusion' => 'Terbitkan Work Order Penggantian Aset',
            'is_identified' => false,
            'officer_2_id' => $rian->id,
            'team' => [$bagus->id],
            'vehicles' => [['vehicle_type' => 'Truk Trailer', 'vehicle_group' => 'Golongan V', 'plate_number' => 'K 9001 MN']],
            'culprits' => [],
            'damaged_items' => $this->guardrail([3, 4, 4, 16]),
        ]);

        // ---- 6. TANPA KERUSAKAN ASET + causer "Lainnya".
        //         is_asset_broken jadi false, total_estimation 0, damaged_items kosong.
        $sync->upsert(self::uuid(6), $bagus, [
            'date_time' => now()->subHours(6)->toDateTimeString(),
            'km' => 2, 'm' => 900, 'route' => 'B', 'lane' => 'Bahu Jalan',
            'weather_condition' => 'Cerah',
            'causer' => 'Lainnya',
            'causer_other' => 'Tumpahan oli dari truk lain',
            'incident_explanation' => 'Kendaraan berhenti di bahu jalan karena mesin mati. Tidak ada aset yang rusak.',
            'action' => ['Pengaturan Arus Lalu Lintas (Trafik)', 'Koordinasi Unit Derek'],
            'analyze' => 'Tidak Ada Kerusakan Aset',
            'conclusion' => 'Selesai',
            'is_identified' => false,
            'officer_2_id' => $rian->id,
            'team' => [$bagus->id],
            'vehicles' => [['vehicle_type' => 'Bus', 'vehicle_group' => 'Golongan II', 'plate_number' => 'AD 1122 GH']],
            'damaged_items' => [],
        ]);

        // ---- 7. MILIK PETUGAS LAIN. Buat menguji 403 dari Postman, bukan cuma test.
        //         Pelapornya $rian, jadi penanda tangan 2 di sini $bagus.
        $sync->upsert(self::uuid(7), $rian, [
            'date_time' => now()->subDays(2)->setTime(11, 0)->toDateTimeString(),
            'km' => 30, 'm' => 0, 'route' => 'A', 'lane' => 'Lajur 1',
            'weather_condition' => 'Cerah', 'causer' => 'Rem Blong / Gagal Fungsi Kendaraan',
            'incident_explanation' => 'Laporan milik JMTO002. Dibuka pakai token JMTO001 harus 403.',
            'analyze' => 'Rusak Ringan', 'is_identified' => false,
            'officer_2_id' => $bagus->id,
            'team' => [$rian->id],
            'damaged_items' => $this->guardrail([0, 1, 1, 4]),
        ]);

        // ---- 8..25. Pengisi halaman kedua. Tanpa ini meta.last_page selalu 1 dan
        //             tombol "muat lagi" di FE tidak pernah bisa dicoba.
        foreach (range(8, 7 + self::FILLER) as $n) {
            $sync->upsert(self::uuid($n), $bagus, [
                'date_time' => now()->subDays($n + 10)->setTime(10, 0)->toDateTimeString(),
                'km' => $n, 'm' => $n * 7 % 1000, 'route' => $n % 2 ? 'A' : 'B',
                'lane' => ['Lajur 1', 'Lajur 2', 'Lajur 3', 'Bahu Jalan'][$n % 4],
                'weather_condition' => ['Cerah', 'Hujan', 'Berkabut'][$n % 3],
                'causer' => 'Kurang Antisipasi / Jaga Jarak',
                'incident_explanation' => "Laporan contoh #{$n} — pengisi halaman, bukan kasus khusus.",
                'analyze' => 'Rusak Ringan',
                'is_identified' => false,
                'officer_2_id' => $rian->id,
                'team' => [$bagus->id],
                'damaged_items' => $this->guardrail([1, 0, 0, 2]),
            ]);
        }

        // ---- 26..28. TIGA CABANG YANG HANYA ADA KARENA PENGESAHAN BA DIPISAH.
        //
        // Kalau suatu saat pengesahannya digabung lagi jadi satu aksi, ketiganya
        // mustahil dibuat dan seeder ini yang jatuh duluan — bukan layar FE yang
        // diam-diam kehilangan datanya.

        // 26. BA Kejadian SUDAH disahkan, BA Kerusakan masih menunggu.
        //     Ini persis keadaan yang digambar di tab 2 layar MCS: BAK bernomor,
        //     BAR bertuliskan "Menunggu Pengesahan". Laporannya tetap
        //     pending_verification, bukan verified.
        $sync->upsert(self::uuid(26), $bagus, [
            'date_time' => now()->subDays(4)->setTime(13, 30)->toDateTimeString(),
            'km' => 15, 'm' => 250, 'route' => 'A', 'lane' => 'Lajur 2',
            'weather_condition' => 'Cerah', 'causer' => 'Pecah Ban',
            'incident_explanation' => 'BA Kejadian sudah disahkan, BA Kerusakan belum. Nilainya masih diperiksa.',
            'action' => ['Pemasangan Rambu Darurat (Cone/Barrier)'],
            'analyze' => 'Rusak Berat',
            'conclusion' => 'Terbitkan Work Order Penggantian Aset',
            'is_identified' => true,
            'settlement_type' => 'Tunai / Transfer',
            'officer_2_id' => $rian->id,
            'team' => [$bagus->id, $rian->id],
            'vehicles' => [['vehicle_type' => 'Minibus/SUV', 'vehicle_group' => 'Golongan I', 'plate_number' => 'AB 3344 QR']],
            'culprits' => [['full_name' => 'Hendra Wijaya', 'vehicle_index' => 0, 'party_role' => 'Pengemudi',
                'national_id' => '3471081007870011', 'driver_license_number' => '3471081007870019',
                'phone_number' => '081533334444', 'address' => 'Jl. Godean KM 4, Sleman',
                'age' => 38, 'occupation' => 'Karyawan swasta']],
            'damaged_items' => $this->guardrail([2, 2, 2, 8]),
        ]);
        $verify->validateIncidentBa($this->report(26), $dewi, []);

        // 27. BA Kejadian disahkan, BA Kerusakan DITOLAK.
        //     Laporannya 'rejected' padahal BAK-nya sudah validated dan bernomor —
        //     kombinasi yang mustahil ada di model satu-aksi. Petugas cuma perlu
        //     membetulkan daftar kerusakannya, kronologinya tidak diutak-atik.
        $sync->upsert(self::uuid(27), $bagus, [
            'date_time' => now()->subDays(5)->setTime(8, 10)->toDateTimeString(),
            'km' => 25, 'm' => 480, 'route' => 'B', 'lane' => 'Lajur 1',
            'weather_condition' => 'Berkabut', 'causer' => 'Kurang Antisipasi / Jaga Jarak',
            'incident_explanation' => 'Volume guardrail yang ditulis petugas tidak cocok dengan foto TKP.',
            'action' => ['Pengaturan Arus Lalu Lintas (Trafik)'],
            'analyze' => 'Rusak Ringan',
            'conclusion' => 'Terbitkan Work Order Perbaikan Parsial',
            'is_identified' => true,
            'settlement_type' => 'Tunai / Transfer',
            'officer_2_id' => $rian->id,
            'team' => [$bagus->id, $rian->id],
            'vehicles' => [['vehicle_type' => 'Truk Box', 'vehicle_group' => 'Golongan III', 'plate_number' => 'AD 5566 ST']],
            'culprits' => [['full_name' => 'Joko Prasetyo', 'vehicle_index' => 0, 'party_role' => 'Pengurus',
                'national_id' => '3374091203900012', 'phone_number' => '081644445555',
                'age' => 36, 'occupation' => 'Ekspedisi']],
            'damaged_items' => $this->guardrail([9, 9, 9, 36]),
        ]);
        $verify->validateIncidentBa($this->report(27), $dewi, []);
        $verify->rejectDamageBa($this->report(27), $dewi, [
            'rejection_reason' => 'Volume Guard Rail Beam 9 batang tidak cocok dengan foto TKP. Mohon dihitung ulang.',
        ]);

        // 28. MENGAJUKAN KERINGANAN, BA Kerusakan sudah disahkan, SURATNYA BELUM MASUK.
        //     Satu-satunya laporan yang masuk hitungan kartu "Dokumen Tertunda (Masa
        //     Tenggang)" di dashboard — laporan #4 punya relief_letter, jadi justru
        //     membuktikan yang sudah menyerahkan surat KELUAR dari hitungan.
        //     Tenggatnya damage_ba.validated_at + config('toll.relief_grace_days').
        $sync->upsert(self::uuid(28), $bagus, [
            'date_time' => now()->subDays(2)->setTime(19, 45)->toDateTimeString(),
            'km' => 33, 'm' => 800, 'route' => 'A', 'lane' => 'Lajur 2',
            'weather_condition' => 'Hujan', 'causer' => 'Rem Blong / Gagal Fungsi Kendaraan',
            'incident_explanation' => 'Pengguna jalan mengajukan keringanan; surat bermaterai belum diserahkan.',
            'action' => ['Koordinasi Unit Derek'],
            'analyze' => 'Rusak Berat',
            'conclusion' => 'Terbitkan Work Order Penggantian Aset',
            'is_identified' => true,
            'settlement_type' => 'Tunai / Transfer',
            'relief_requested' => true,
            'pledged_amount' => 1500000,
            'officer_2_id' => $rian->id,
            'team' => [$bagus->id, $rian->id],
            'vehicles' => [['vehicle_type' => 'Truk Trailer', 'vehicle_group' => 'Golongan V', 'plate_number' => 'K 7788 UV']],
            'culprits' => [['full_name' => 'Agus Salim', 'vehicle_index' => 0, 'party_role' => 'Pemilik',
                'national_id' => '3374052508750013', 'driver_license_number' => '3374052508750018',
                'phone_number' => '081755556666', 'address' => 'Jl. Raya Solo KM 12, Klaten',
                'age' => 50, 'occupation' => 'Pengusaha']],
            'damaged_items' => $this->guardrail([3, 3, 3, 12]),
        ]);
        $this->photos(self::uuid(28), $bagus, $this->buktiLapangan(28));
        $verify->validateIncidentBa($this->report(28), $dewi, []);
        $verify->validateDamageBa($this->report(28), $dewi);

        // Bukti Lapangan untuk laporan yang belum punya foto sama sekali — supaya
        // layar MCS bisa dicoba tanpa login petugas dan mengunggah sendiri (permintaan
        // FE 1 Okt). #1/#2/#4/#28 tidak ikut: punya set sendiri, dan #2 sengaja cuma
        // satu foto buram karena itu alasan penolakannya. photos() menomori berkas
        // dari 1 per panggilan, jadi satu laporan WAJIB satu panggilan — panggilan
        // kedua menimpa baris yang pertama.
        foreach (array_diff(range(3, 28), [4, 28]) as $n) {
            $report = $this->report($n);
            $this->photos($report->uuid, $report->reporter, $this->buktiLapangan($n));
        }
    }

    /** Dua foto yang dibaca kartu "Bukti Fisik Lapangan" di layar MCS. */
    private function buktiLapangan(int $n): array
    {
        return ['scene_photo' => "Foto TKP #{$n}", 'vehicle_photo' => "Foto Kendaraan #{$n}"];
    }

    /**
     * uuid tetap dan terbaca mata: laporan ke-N → 0000000N-0000-4000-8000-00000000000N.
     *
     * public karena WorkOrderSeeder menunjuk laporan yang sama untuk menerbitkan Work
     * Order contohnya — kalau uuid-nya diketik ulang di sana, satu digit beda sudah cukup
     * untuk membuat seeder mati dengan "laporan tidak ditemukan" yang tidak jelas asalnya.
     */
    public static function uuid(int $n): string
    {
        return sprintf('%08d-0000-4000-8000-%012d', $n, $n);
    }

    /** @return list<string> */
    private function uuids(): array
    {
        return array_map(self::uuid(...), range(1, 7 + self::FILLER + self::CABANG_MCS));
    }

    /**
     * Komponen guardrail yang dipakai laporan contoh, URUT sama dengan
     * CatalogSeeder. Disebut namanya, bukan "semua harga yang masih berlaku":
     * katalog sekarang 25 baris dan jenis guardrail sendiri punya enam komponen,
     * jadi tanpa daftar ini kuantitas dipasangkan menurut urutan id dan gejalanya
     * laporan contoh yang menyebut barang yang tidak pernah rusak.
     */
    private const KOMPONEN = [
        'Guard Rail Beam (4000X312X2.7)',
        'Guard Rail Post (1800X4,5 mm)',
        'Guard Rail, Blocking Piece (350X4,5 mm)',
        'Mur Baut',
    ];

    /**
     * Empat komponen Guardrail Tipe A dengan kuantitas yang diminta, urut sama
     * dengan self::KOMPONEN. Kuantitas 0 dibuang — tidak ada baris kerusakan
     * berisi nol barang, dan `quantity` divalidasi `gt:0`.
     *
     * @param  array{int,int,int,int}  $quantities
     */
    private function guardrail(array $quantities): array
    {
        return CatalogPrice::whereNull('effective_to')
            ->whereHas('assetCatalog', fn ($q) => $q
                ->whereIn('component', self::KOMPONEN)
                ->whereHas('assetType', fn ($t) => $t->where('name', 'Guardrail Kendaraan Tipe A')))
            ->orderBy('asset_catalog_id')->get()
            ->values()
            ->map(fn (CatalogPrice $price, int $i) => [
                'asset_catalog_id' => $price->asset_catalog_id,
                'catalog_price_id' => $price->id,
                'quantity' => $quantities[$i] ?? 0,
                'damage_percentage' => 100,
            ])
            ->reject(fn (array $row) => $row['quantity'] <= 0)
            ->values()->all();
    }

    /** Laporan yang baru saja di-upsert, siap dioper ke IncidentVerificationService. */
    private function report(int $n): IncidentReport
    {
        return IncidentReport::findOrFail(self::uuid($n));
    }

    /**
     * Berkas contoh + barisnya di `documents`.
     *
     * File-nya betulan ditulis ke disk, bukan cuma barisnya: `GET /documents/{id}`
     * menjawab 404 kalau berkasnya tidak ada, jadi baris tanpa file justru memberi
     * FE kesan endpoint-nya rusak.
     *
     * Path-nya disusun dengan pola yang sama dengan DocumentStorageService —
     * `insiden/{report_uuid}/{file_uuid}.png` — dan `documents.path` itu unique,
     * jadi seeding ulang menimpa, bukan menggandakan.
     *
     * @param  array<string,string>  $types  document_type => tulisan di gambarnya
     */
    private function photos(string $reportUuid, User $uploader, array $types): void
    {
        $i = 0;

        foreach ($types as $type => $label) {
            $fileUuid = sprintf('%s-0000-4000-8000-%012d', substr($reportUuid, 0, 8), ++$i);
            $path = "insiden/{$reportUuid}/{$fileUuid}.png";

            Storage::disk('local')->put($path, PlaceholderImage::png($label));

            Document::updateOrCreate(['path' => $path], [
                'documentable_type' => 'incident_report',
                'documentable_id' => $reportUuid,
                'document_type' => $type,
                'mime_type' => 'image/png',
                'size_bytes' => Storage::disk('local')->size($path),
                'uploaded_by' => $uploader->id,
                'uploaded_at' => now(),
            ]);
        }
    }

}
