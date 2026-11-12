<?php

/**
 * Daftar pilihan tetap untuk form laporan insiden dan penerbitan Work Order.
 *
 * SATU sumber, dipakai tiga tempat (yang di bagian work_orders cuma dua — tidak ikut
 * bundel luring, alasannya di sana):
 *   1. CHECK constraint di migration        (database/migrations/2026_08_27_0000*)
 *   2. Rule::in() di FormRequest            (app/Http/Requests/Api/SyncIncidentReportRequest.php)
 *   3. GET /sync/bootstrap                  (app/Services/OfflineBundleService.php)
 *
 * Kalau daftar ini diketik ulang di salah satu tempat, satu huruf beda sudah cukup
 * untuk membuat CHECK menolak apa yang validasi terima — dan ketahuannya di produksi.
 *
 * VALUE-nya bahasa Indonesia siap tampil, bukan snake_case Inggris. Nama kolom tetap
 * Inggris. Alasannya FE tidak perlu file pemetaan sama sekali: apa yang disimpan itu
 * juga yang ditampilkan di dropdown.
 *
 * ponytail: value = label, jadi mengubah kata-katanya nanti bukan ganti string —
 * itu `UPDATE` seluruh baris + `DROP`/`ADD CONSTRAINT`. Pisahkan value dari label
 * (kolom/tabel sendiri) kalau suatu saat labelnya sering direvisi atau perlu dua bahasa.
 *
 * ponytail: daftar ini ikut ke bundel luring dan mengendap di HP sampai sync berikutnya.
 * MENAMBAH nilai aman kapan saja. MEMBUANG atau MENGUBAH kata sebuah nilai tidak aman —
 * laporan yang terlanjur diketik luring dengan nilai lama kena 422 waktu sync dan petugas
 * tidak punya jalan membetulkannya di lapangan. Ubah/buang harus barengan siklus sinkron.
 */
return [

    // ---- incident_report ----------------------------------------------------

    // 'Lainnya' wajib disertai incident_report.causer_other. Dijaga CHECK biconditional
    // di migration dan required_if/prohibited_unless di FormRequest.
    'causer' => [
        'Mengantuk / Micro-sleep',
        'Pecah Ban',
        'Rem Blong / Gagal Fungsi Kendaraan',
        'Kurang Antisipasi / Jaga Jarak',
        'Faktor Cuaca (Jalan Licin/Berkabut)',
        'Menghindari Objek / Hewan',
        'Lainnya',
    ],

    // Satu-satunya yang boleh dipilih lebih dari satu. Disimpan jsonb, bukan teks
    // berkoma — nilainya sendiri mengandung '/' dan '(' dan suatu saat bisa berkoma.
    'action' => [
        'Pemasangan Rambu Darurat (Cone/Barrier)',
        'Pengaturan Arus Lalu Lintas (Trafik)',
        'Evakuasi Pengemudi / Korban',
        'Koordinasi Unit Derek',
        'Koordinasi Kepolisian / Medis',
        'Pembersihan Lajur dari Puing',
        // 6 Okt: BAK yang cuma melaporkan kejadian, tanpa tindakan apa pun. TIDAK
        // boleh dipilih bersama tindakan lain (SyncIncidentReportRequest). Menambah
        // nilai di sini butuh migration — CHECK incident_report_action_check dibangun
        // dari daftar ini saat migrate (2026_10_06_000000).
        'Tidak Ada',
    ],

    // is_asset_broken diturunkan dari sini, makanya kolomnya NOT NULL.
    'analyze' => [
        'Rusak Berat',
        'Rusak Ringan',
        'Tidak Ada Kerusakan Aset',
    ],

    // Rekomendasi petugas, BUKAN pemicu. Work Order terbit dari validasi damage_ba
    // di Fase 3 — kalau dipasang ke sini, kerusakan tabrak lari tidak pernah diperbaiki.
    'conclusion' => [
        'Terbitkan Work Order Penggantian Aset',
        'Terbitkan Work Order Perbaikan Parsial',
        'Selesai',
    ],

    'lane' => [
        'Lajur 1',
        'Lajur 2',
        'Lajur 3',
        'Bahu Jalan',
    ],

    'weather' => [
        'Cerah',
        'Hujan',
        'Berkabut',
    ],

    // Dropdown di layar TIGA pilihan, kolomnya DUA. 'Mengajukan Keringanan' bukan
    // nilai ketiga — itu 'Tunai / Transfer' + relief_requested = true. SK Pasal 4
    // ayat 1 cuma mengenal dua cara bayar. Lihat KONVENSI-BACKEND.md §7.
    'settlement' => [
        'Tunai / Transfer',
        'Perbaikan oleh Rekanan',
    ],

    // Arah, bukan nama ruas. Sistem ini untuk satu ruas.
    'route' => ['A', 'B'],

    // Jenis elemen jalan tempat kejadian. Slot "Ramp ......" di formulir BA Kejadian
    // dan "on/off ramp ......" di BA Kerusakan (SK hal. 18-19).
    //
    // Alasan sebenarnya bukan formulir: unit maintenance harus tahu TITIK-nya.
    // Regu yang dikirim ke ramp keluar tidak bisa dikirim ke jalur utama.
    //
    // 'ramp' dipecah jadi MASUK dan KELUAR (2 Sep) karena keduanya tempat yang
    // berbeda: on-ramp jalur kendaraan bergabung ke jalur utama, off-ramp jalur
    // kendaraan meninggalkannya. Formulir BA Kerusakan sendiri menulis
    // "/ on/off ramp ......" — satu nilai 'ramp' tidak pernah bisa mengisinya.
    //
    // Value-nya IKUT konvensi 1 Sep (Indonesia, siap tampil) seperti enum lain.
    // Sempat dikecualikan tetap Inggris demi seragam dengan work_orders.location_type;
    // pengecualian itu dibuang karena work_orders masih nol baris dan Fase 4 belum
    // ditulis — jadi tidak ada yang dijaga, yang ada cuma snake_case Inggris bocor
    // mentah ke Berita Acara yang dipakai menagih orang.
    //
    // work_orders.location_type memakai daftar yang SAMA, dibaca dari sini juga —
    // bukan daftar kedua yang diketik ulang.
    'location_type' => [
        'Jalur Utama',
        'Ramp Masuk',
        'Ramp Keluar',
        'Gerbang Tol',
        'Rest Area',
    ],

    // ---- involved_vehicles --------------------------------------------------

    // Jenis bodi. Sebelumnya kolom ini keliru diisi golongan ('class_1').
    'vehicle_type' => [
        'Sedan',
        'Minibus/SUV',
        'Pick-up',
        'Truk Box',
        'Truk Tangki',
        'Truk Trailer',
        'Bus',
    ],

    // Golongan tarif tol. Sebelumnya ketikan bebas.
    'vehicle_group' => [
        'Golongan I',
        'Golongan II',
        'Golongan III',
        'Golongan IV',
        'Golongan V',
    ],

    // ---- culprit ------------------------------------------------------------

    // Status Pihak Kesatu di formulir BA Kerusakan: "Dalam hal ini disebut sebagai
    // pemilik / pengemudi / pengurus kendaraan jenis ...". Tiga pilihan yang
    // dilingkari di kertas.
    //
    // Kolomnya NULLABLE dan memang harus: laporan yang terlanjur diketik luring
    // dengan aplikasi versi lama tidak boleh kena 422 waktu sync, dan tabrak lari
    // nol baris culprit. Yang kosong tercetak sebagai titik-titik, seperti kertasnya.
    'party_role' => [
        'Pemilik',
        'Pengemudi',
        'Pengurus',
    ],

    // ---- work_orders --------------------------------------------------------

    // DUA tempat, bukan tiga: CHECK constraint di migration work_orders dan Rule::in()
    // di StoreWorkOrderRequest. TIDAK ikut GET /sync/bootstrap — bundel luring itu bekal
    // Petugas Lapangan, dan Unit Maintenance bekerja di web dalam intranet.
    //
    // VALUE-nya BAHASA INDONESIA, sama seperti pilihan form insiden di atas —
    // permintaan Rafiq 3 Sep, membatalkan keputusan Inggris beberapa jam sebelumnya.
    // Alasan lama ("yang dilokalkan cuma yang tercetak di Berita Acara") ditinggalkan
    // karena membuat SATU aplikasi punya dua kosakata untuk hal yang bentuknya sama:
    // petugas memilih 'Rusak Berat' di satu layar, unit maintenance memilih 'high' di
    // layar sebelahnya. Yang menanggung bedanya FE, dan tidak ada yang membayar
    // ongkosnya selain kerapian.
    //
    // repair_status TIDAK ikut ke sini dan tetap Inggris — dia STATUS yang diturunkan
    // server, bukan pilihan yang dikirim orang. Preseden `report_status` dan
    // `validation_status`: tempatnya const model + CHECK, bukan daftar pilihan.

    // Kenapa Work Order ini terbit. 'Insiden' berasal dari damage_ba yang disahkan
    // (Fase 3); tiga sisanya tidak melalui Fase 1-3 sama sekali dan karena itu WAJIB
    // membawa asset_catalog_id — tidak ada damaged_items untuk dibaca.
    //
    // 'Insiden' TIDAK BOLEH BERKOMA, dan itu batasan teknis bukan gaya: dia muncul di
    // dalam string aturan `required_if:trigger_source,Insiden` di StoreWorkOrderRequest,
    // dan Laravel memecah string itu pada koma. Nilai berkoma akan terbaca sebagai dua
    // argumen dan aturannya diam-diam berhenti menjaga apa pun.
    'wo_trigger_source' => [
        'Insiden',
        'Temuan Patroli',
        'Preventif',
        'Aus Normal',
    ],

    // TIGA tingkat, bukan empat. Desain Figma menggambar Urgent/High/Med/Low, tapi ERD
    // Bagian 8 cuma mengenal tiga dan urutan otoritas menaruh ERD di atas desain UI.
    // Belum ditanyakan ke kantor apakah Urgent dan High memang dua tingkat berbeda —
    // kalau iya, menambah nilai ke daftar ini aman kapan saja (yang tidak aman itu
    // membuang atau mengubah kata sebuah nilai yang sudah dipakai baris lama).
    //
    // TIDAK menentukan sla_deadline. Jam per tingkat belum pernah disebut kantor, dan
    // menebaknya berarti tenggat yang salah tercetak tanpa ada yang tahu.
    'wo_priority' => [
        'Tinggi',
        'Sedang',
        'Rendah',
    ],

    // Hasil evaluasi Fase 6. IKUT dilokalkan karena ini nilai yang DIKIRIM orang dari
    // layar, sekelas dengan settlement_type — beda dari repair_status yang diturunkan
    // server dan karena itu tetap Inggris.
    //
    // DUA nilai sejak 5 Okt (keputusan PO): 'Dikembalikan ke Regu' dibuang karena
    // perlakuannya sama persis dengan 'Dikerjakan Ulang'. Baris lama yang memakainya
    // diubah jadi 'Dikerjakan Ulang' oleh migration 2026_10_05_000001.
    'wo_review_outcome' => [
        'Diterima',
        'Dikerjakan Ulang',
    ],

    // ---- Fase 5: work_order_logs --------------------------------------------

    // Kelima daftar di bawah dipakai aplikasi Petugas Maintenance yang LURING, dan
    // itu yang memaksa mereka pindah ke sini dari CHECK mentah di migration: salinan
    // di HP yang melenceng dari CHECK menghasilkan 422 di bahu jalan, tempat paling
    // buruk untuk ketahuan. Semuanya berawalan `wo_`, jadi OfflineBundleService sudah
    // otomatis menahannya dari bundel Petugas Patroli yang tidak memakainya.

    // Jenis baris di garis waktu Work Order. TETAP INGGRIS, dan itu bukan kelalaian:
    // nilai ini TIDAK PERNAH TAMPIL di layar — dia memilih formulir mana yang dibuka,
    // sekelas dengan repair_status. Yang tampil dari baris log adalah `bda_stage`,
    // `reason`, dan `notes`.
    //
    // 'apd_check' dan 'obstacle' baru 3 Sep. Yang pertama gerbang APD sebelum regu
    // berangkat; yang kedua lapor kendala TANPA melepas tugas — pekerjaannya tetap di
    // tangan petugas, jadi dia sengaja tidak mengubah repair_status sama sekali.
    'wo_log_entry_type' => [
        'start',
        'apd_check',
        'progress',
        'shift_handover',
        'obstacle',
        'complete',
        'rework',
    ],

    // Tahap bukti foto. TETAP INGGRIS karena desainnya sendiri mencetaknya begitu —
    // "Before / During / After" dan "Dokumentasi B-D-A" adalah istilah yang dipakai
    // orangnya, bukan terjemahan yang belum dikerjakan.
    //
    // BUKAN tiga slot tetap. Satu tahap boleh punya banyak baris log pada jam yang
    // berbeda; pekerjaan dua hari dengan pergantian regu di tengahnya tidak muat di
    // tiga baris.
    'wo_bda_stage' => [
        'before',
        'during',
        'after',
    ],

    // Gerbang APD sebelum regu berangkat, dicentang PER PETUGAS — satu baris log per
    // orang, bukan satu per Work Order.
    //
    // Disimpan sebagai daftar, bukan satu boolean 'apd_lengkap': kalau kantor suatu
    // saat membolehkan sebagian (petugas yang tidak turun ke lajur), baris lama tetap
    // menceritakan apa yang benar-benar dicentang hari itu. Boolean membuang informasi
    // yang tidak bisa dipulihkan.
    //
    // Sekarang keempatnya WAJIB — keputusan Rafiq 3 Sep, dan aturannya di FormRequest
    // bukan di skema, supaya melonggarkannya nanti tidak berarti migration.
    'wo_apd_items' => [
        'Helm Safety',
        'Rompi Reflektif',
        'Sepatu Safety',
        'Sarung Tangan',
    ],

    // Isi dropdown "Alasan Oper Shift". Kolomnya `work_order_logs.reason`, TERPISAH
    // dari `notes` yang menampung "Catatan Serah Terima" — alasannya bisa dihitung
    // ("berapa kali oper shift karena material belum datang"), catatannya tidak.
    // Ditumpuk jadi satu, tepat separuh yang bisa dihitung itu yang hilang.
    //
    // BELUM DIVERIFIKASI KANTOR. Diturunkan dari alur, dipakai atas persetujuan Rafiq
    // 3 Sep, dan pertanyaannya ada di 002 bersama pertanyaan vendor luar — orangnya
    // sama, Unit Maintenance. Gagalnya TIDAK senyap: daftar yang salah langsung
    // kelihatan sebagai dropdown aneh di tangan orang lapangan.
    //
    // 'Lainnya' tidak punya kolom pendamping seperti causer_other — `notes` di baris
    // yang sama sudah teks bebas, dan FormRequest mewajibkannya terisi.
    'wo_handover_reason' => [
        'Jam Kerja Shift Berakhir',
        'Pekerjaan Lintas Hari',
        'Petugas Digantikan',
        'Lainnya',
    ],

    // Isi dropdown "Lapor Kendala" — kolom yang sama dengan di atas, daftar berbeda,
    // dipisah CHECK bersyarat menurut entry_type. Sama-sama belum diverifikasi kantor.
    //
    // Melaporkan kendala TIDAK melepas tugas dan tidak mengubah status. Keputusan
    // Rafiq 3 Sep: yang digambar Figma sebagai "Tolak / Lapor Kendala" dibangun
    // sebagai lapor kendala saja. Menolak penugasan butuh repair_status baru dan
    // menyentuh Mentok #4 yang belum dijawab siapa pun.
    'wo_obstacle_reason' => [
        'Material Belum Tersedia',
        'Cuaca Tidak Mendukung',
        'Akses Lokasi Tertutup',
        'Peralatan Rusak',
        // 5 Okt, disetujui PO (desain Figma "Kendala Arus Lalin").
        'Arus Lalu Lintas Padat',
        'Lainnya',
    ],

    // Keterangan di bawah tiap pilihan dropdown alasan (desain Figma). PETA nama →
    // keterangan, TERPISAH dari dua daftar di atas: daftar itu menyusun CHECK constraint,
    // dan mengubah bentuknya jadi peta berarti menyentuh migration. Kunci yang tidak ada
    // di sini cukup tampil tanpa keterangan.
    //
    // Dikirim ke bundel luring sebagai `option_descriptions`, BUKAN di dalam `options`:
    // kontrak `options` itu grup → daftar, dan FE memanggil .map() ke tiap grup. Peta di
    // sana membuat seluruh pilihan gagal tersimpan di HP (insiden 2 Okt).
    //
    // Kalimatnya DRAF dari contoh desain, belum diverifikasi kantor — sama statusnya
    // dengan daftar alasannya sendiri.
    'wo_reason_descriptions' => [
        'Jam Kerja Shift Berakhir' => 'Jam kerja regu habis, pekerjaan dilanjutkan shift berikutnya.',
        'Pekerjaan Lintas Hari' => 'Pekerjaan memang butuh lebih dari satu hari kerja.',
        'Petugas Digantikan' => 'Petugas berhalangan dan digantikan rekan lain.',
        'Material Belum Tersedia' => 'Pasokan aspal dingin, guardrail, atau baut tertunda.',
        'Cuaca Tidak Mendukung' => 'Hujan deras, angin kencang, atau jarak pandang terbatas.',
        'Akses Lokasi Tertutup' => 'Lajur tidak bisa ditutup atau lokasi terhalang kendaraan/kegiatan lain.',
        'Peralatan Rusak' => 'Alat kerja atau kendaraan operasional tidak bisa dipakai.',
        'Arus Lalu Lintas Padat' => 'Lajur tidak aman ditutup karena volume kendaraan tinggi.',
        'Lainnya' => 'Jelaskan di catatan.',
    ],
];
