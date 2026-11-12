<?php

/**
 * Ketetapan ruas Jogja-Solo yang bukan pilihan petugas.
 *
 * Bedanya dengan config/enums.php: yang di sana daftar pilihan yang muncul di form
 * insiden dan ikut ke bundel luring. Yang di sini ketetapan operasional dan hukum —
 * pola nomor, batas seksi, tenggat, dan orang yang menandatangani baris "Mengetahui".
 * Tidak satu pun dari ini pernah jadi dropdown.
 */
return [

    // Format nomor Berita Acara. Contoh nyata di SK: 087/TM.JOGLO/VIII/2026
    //
    // Satu string di sini, bukan disebar ke kode (KONVENSI-BACKEND.md bagian 5).
    // Urutannya: nomor urut, kode bulan Romawi, tahun.
    //
    // Dipakai TIGA counter yang urutannya sendiri-sendiri — report_number,
    // incident_ba_number, damage_ba_number (keputusan 1 Sep). Formatnya sama,
    // angkanya tidak: BA Kerusakan cuma terbit kalau ada kerusakan, jadi urutannya
    // selalu tertinggal di belakang BA Kejadian.
    'number_format' => '%03d/TM.JOGLO/%s/%d',

    // Nomor Work Order. Contoh: 004/JMTM/TM.JOGLO/IX/2026
    //
    // Format TERPISAH dari nomor Berita Acara, dan bedanya cuma sisipan JMTM. Alasannya
    // di kertas yang dipegang regu perbaikan: perintah kerja dan Berita Acara itu dua
    // jenis dokumen dengan akibat hukum yang berbeda, dan nomor yang bentuknya sama
    // persis membuat keduanya tidak bisa dibedakan sekilas.
    //
    // Ekornya sengaja tetap /{tahun} dan awalannya tetap angka: NumberGenerator memindai
    // nomor terakhir dengan LIKE '%/{tahun}' dan substring(... from '^[0-9]+'). Format
    // yang menyimpang dari dua pola itu membuat penerbitan nomor diam-diam mulai dari 1
    // lagi tiap kali.
    'wo_number_format' => '%03d/JMTM/TM.JOGLO/%s/%d',

    // Seksi jalan tol, untuk slot "Jalan Tol Seksi ........" di formulir BA Kerusakan.
    //
    // DITURUNKAN DARI km, bukan kolom. Seksi itu fungsi dari KM; menyimpannya per
    // baris menggandakan satu fakta dan membuka kemungkinan seksi melenceng dari km
    // di baris yang sama.
    //
    // ponytail: rentang KM ini BELUM diverifikasi kantor. Angkanya diturunkan dari
    // PANJANG tiap seksi (riset 1 Sep: 42,3 / 16-23,4 / 38,57 km, total 96,57 km),
    // bukan dari patok batas resmi. Nyambung ke Mentok #2 — belum jelas KM dihitung
    // dari Kartasura atau lanjutan KM Trans-Jawa.
    //
    // Kalau km jatuh di luar semua rentang, IncidentReport::section() mengembalikan
    // null dan slotnya tercetak KOSONG — bukan dibulatkan ke seksi terdekat. Seksi
    // yang salah cetak di dokumen tagihan gagalnya senyap, kelas yang sama dengan
    // selisih 6,5%: baru ketahuan waktu dicocokkan dengan kertas berbulan kemudian.
    // `name` cuma ANGKA-nya, bukan "Seksi 1": kata "Seksi" sudah tercetak di
    // kertasnya, jadi yang disalin utuh berbunyi "Jalan Tol Seksi Seksi 1". Sama
    // persis dengan alasan ba_location_slot di bawah. IncidentReport::section()
    // satu-satunya pembacanya, dan tugasnya memang mengisi slot itu.
    'sections' => [
        ['name' => '1', 'from_km' => 0, 'to_km' => 42],
        ['name' => '2', 'from_km' => 42, 'to_km' => 66],
        ['name' => '3', 'from_km' => 66, 'to_km' => 97],
    ],

    // Jam kerja tiap shift, untuk slot "( shift )" di BA Kejadian.
    //
    // DITURUNKAN DARI date_time, bukan diketik MCS. Yang tahu shift itu petugas yang
    // bertugas, dan jam kejadiannya sudah ada di laporan — shift itu fungsi dari jam,
    // sama seperti seksi fungsi dari KM. MCS yang mengetiknya belakangan cuma menambah
    // satu tempat yang bisa melenceng dari kolom di sebelahnya.
    //
    // `to` EKSKLUSIF: jam 14:00 tepat masuk Shift 2, bukan Shift 1. Shift 3 melewati
    // tengah malam (from > to) dan IncidentReport::shift() membacanya sebagai
    // "jam >= 22 ATAU jam < 6".
    //
    // ponytail: batas jam ini BELUM diverifikasi kantor — diturunkan dari satu contoh
    // di Alur 2.1 ("Shift 2 - 14:00 s.d. 22:00") dan pola 3 shift 8 jam. Kalau
    // ternyata berbeda, yang berubah cuma angka di sini.
    'shifts' => [
        '1' => ['from' => 6, 'to' => 14],
        '2' => ['from' => 14, 'to' => 22],
        '3' => ['from' => 22, 'to' => 6],
    ],

    // Isi slot "/ Ramp ......" (BA Kejadian) dan "/ on/off ramp ......" (BA Kerusakan)
    // untuk tiap nilai incident_report.location_type.
    //
    // Perlu pemetaan karena kata "Ramp" SUDAH tercetak di kertasnya: nilai
    // 'Ramp Masuk' dicetak apa adanya berbunyi "Ramp Ramp Masuk". Yang diisi cuma
    // sisanya. Pemetaan tampilan, bukan pemetaan bahasa — value-nya sendiri sudah
    // Indonesia, jadi ini tidak melanggar konvensi "value = label".
    //
    // 'Jalur Utama' sengaja KOSONG: slotnya cuma untuk yang bukan jalur utama, dan
    // "Ramp Jalur Utama" membaca seolah ada ramp bernama itu.
    //
    // Nilai yang tidak terdaftar di sini dicetak apa adanya — daftar ini boleh
    // tertinggal dari config/enums.php tanpa membuat cetakannya kosong diam-diam.
    'ba_location_slot' => [
        'Jalur Utama' => '',
        'Ramp Masuk' => 'Masuk',
        'Ramp Keluar' => 'Keluar',
    ],

    // Arah ramp untuk slot "/ on/off ramp ......" di BA Kerusakan.
    //
    // "on/off" di kertasnya PILIH-SATU, sama seperti "pemilik / pengemudi / pengurus"
    // beberapa baris di atasnya: yang dipakai dilingkari, dua yang lain tetap
    // tercetak. Di sini yang terpilih ditebalkan.
    //
    // Yang tidak terdaftar (Jalur Utama, Gerbang Tol, Rest Area) tidak menebalkan
    // apa pun — "on" maupun "off" tidak ada yang benar untuk gerbang tol.
    'ba_ramp_direction' => [
        'Ramp Masuk' => 'on',
        'Ramp Keluar' => 'off',
    ],

    // Tenggat penyerahan surat permohonan keringanan, dihitung sejak damage_ba
    // DISAHKAN. Jawaban kantor 1 Sep: maksimal seminggu — kendaraannya ditahan
    // Jasa Marga, jadi yang bersangkutan pasti datang mengurus.
    //
    // ponytail: config, bukan kolom. Tenggatnya turunan dari damage_ba.validated_at,
    // jadi menyimpannya per baris menggandakan satu fakta. Jadikan kolom HANYA kalau
    // tenggatnya pernah berubah dan baris lama harus tetap memakai angka lama.
    //
    // BELUM DIJAWAB KANTOR: apa akibatnya kalau lewat tenggat. Sampai dijawab,
    // sistem cuma MEMANTAU — tidak ada perubahan status, tidak ada penolakan
    // otomatis. Menebak akibat hukum jauh lebih mahal daripada menampilkan daftar.
    'relief_grace_days' => 7,

    // Baris "Mengetahui" di KEDUA Berita Acara.
    //
    // Deputy AM Traffic Management menandatangani, tapi BUKAN pengguna sistem: tidak
    // punya akun, tidak melakukan aksi apa pun. Jadi config, bukan baris di `users` —
    // menambah user yang tidak pernah login cuma memberi orang alasan membuatkan
    // password untuknya.
    //
    // Kosong = baris tanda tangannya tercetak kosong, seperti kertas yang diisi
    // tangan sekarang. Bukan error.
    'deputy_am_tm' => [
        'name' => env('DEPUTY_AM_TM_NAME'),
        'nik' => env('DEPUTY_AM_TM_NIK'),
    ],
];
