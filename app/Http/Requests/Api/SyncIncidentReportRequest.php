<?php

namespace App\Http\Requests\Api;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Payload sinkronisasi laporan insiden — induk + seluruh baris anak sekaligus.
 *
 * Yang sengaja TIDAK diterima dari HP, walau kolomnya ada:
 * - report_number    terbit saat MCS mengesahkan
 * - user_id          diambil dari token
 * - sync_status      selalu 'sent' kalau sampai di sini
 * - report_status    selalu 'pending_verification'
 * - total_estimation dihitung ulang server
 * - subtotal         dihitung ulang server, angka kiriman HP diabaikan
 * - is_asset_broken  diturunkan server dari `analyze` — dua sumber untuk satu fakta
 *                    cuma menghasilkan kemungkinan bertentangan
 *
 * Daftar nilai enum ada di config/enums.php, satu sumber bareng CHECK constraint
 * di migration dan bundel luring di OfflineBundleService. Rule::in() dipakai, BUKAN
 * bentuk string 'in:a,b,c' — nilainya kalimat, dan bentuk string pecah diam-diam
 * begitu ada satu nilai yang mengandung koma.
 */
class SyncIncidentReportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Laporan yang datang jam 10:03 mengaku terjadi 18:00 bukan "mencurigakan" —
            // itu tidak mungkin, dan satu-satunya bagian kasus ini yang boleh diputuskan
            // server sendiri. Sisanya penilaian MCS, bukan `if` di sini.
            //
            // Offline-first tidak kena: laporan yang diketik di bahu jalan kemarin jam
            // 18:00 lalu baru tersinkron pagi ini tetap lolos — 18:00 kemarin itu masa lalu.
            //
            // Belum ada toleransi jam HP yang maju. Kalau di lapangan banyak perangkat
            // yang jamnya maju semenit-dua dan petugas kena 422 untuk kejadian yang
            // betul-betul baru saja terjadi, longgarkan jadi `before_or_equal:+15 minutes`
            // — JANGAN dibuang.
            'date_time' => ['required', 'date', 'before_or_equal:now'],
            // km + route WAJIB, m + lane boleh kosong. Kolomnya nullable di database
            // dan tetap begitu — yang dijaga di sini apa yang boleh MASUK, bukan apa
            // yang boleh tersimpan.
            //
            // Pasangan (route, km) itu satu-satunya jangkar analisis titik rawan (ERD
            // Bagian 1), dan dia juga yang dipakai mengirim regu perbaikan. Kosong
            // berarti BA mencetak "KM ... + ... jalur ..." kosong dan slot Seksi ikut
            // kosong, di dokumen yang dipakai menagih orang.
            //
            // `m` dan `lane` sengaja TIDAK ikut wajib: keduanya bisa benar-benar tidak
            // diketahui pasti di kejadian yang ramai, dan tebakan yang dipaksa lebih
            // buruk daripada slot kosong yang dilingkari tangan.
            // max 32767 = batas kolom smallint, BUKAN panjang ruas. Tanpa ini `km: 40000`
            // lolos validasi lalu mati di Postgres (SQLSTATE 22003) — 500, dan di jalur
            // ini akibatnya lebih buruk daripada di web: HP offline-first menganggap 500
            // sebagai gangguan sementara dan mengirim ulang laporan yang sama selamanya.
            'km' => ['required', 'integer', 'min:0', 'max:32767'],
            'm' => ['nullable', 'integer', 'min:0', 'max:999'],
            'lane' => ['nullable', Rule::in(config('enums.lane'))],
            'route' => ['required', Rule::in(config('enums.route'))],
            // GPS HP saat melapor — sumber titik peta "Lokasi Presisi" di Work Order.
            // Opsional: GPS bisa mati atau ditolak izinnya, dan KM tetap jangkar utama.
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            // Nullable walau kolomnya NOT NULL: default 'Jalur Utama' di database yang
            // mengisinya, supaya kiriman aplikasi versi lama tetap masuk. Menolak
            // laporan yang sudah terlanjur diketik luring karena ada field baru itu
            // 422 yang petugas tidak punya cara membetulkannya di bahu jalan.
            'location_type' => ['nullable', Rule::in(config('enums.location_type'))],
            'weather_condition' => ['nullable', Rule::in(config('enums.weather'))],
            'causer' => ['required', Rule::in(config('enums.causer'))],
            // Dua arah, sama seperti CHECK di migration: 'Lainnya' wajib punya teks,
            // selain 'Lainnya' teksnya wajib kosong. Kalau cuma dijaga satu arah,
            // teks lama nyangkut setelah petugas ganti pilihan ke kategori betulan.
            'causer_other' => [
                'nullable', 'string', 'max:120',
                'required_if:causer,Lainnya',
                'prohibited_unless:causer,Lainnya',
            ],
            'incident_explanation' => ['nullable', 'string'],
            // Satu-satunya pilihan jamak. `distinct` bukan hiasan: CHECK `<@` di
            // database menerima duplikat (["A","A"] tetap subset), jadi ini
            // satu-satunya yang menolaknya.
            //
            // 'Tidak Ada' (6 Okt) harus berdiri sendiri: ["Tidak Ada", "Koordinasi Unit
            // Derek"] menyatakan dua hal yang saling membantah di satu laporan.
            'action' => ['nullable', 'array', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_array($value) && in_array('Tidak Ada', $value, true) && count($value) > 1) {
                    $fail('"Tidak Ada" tidak bisa dipilih bersama tindakan lain.');
                }
            }],
            'action.*' => ['distinct', Rule::in(config('enums.action'))],
            'analyze' => ['required', Rule::in(config('enums.analyze'))],
            'conclusion' => ['nullable', Rule::in(config('enums.conclusion'))],
            'is_identified' => ['required', 'boolean'],
            'additional_cost' => ['nullable', 'numeric', 'min:0'],
            'settlement_type' => ['nullable', Rule::in(config('enums.settlement'))],
            'relief_requested' => ['nullable', 'boolean'],
            'pledged_amount' => ['nullable', 'numeric', 'min:0'],

            // Penanda tangan 2 BA Kejadian (redesain 16 Sep). Penanda tangan 1 tidak
            // ada di sini — itu `user_id`, pelapornya sendiri.
            //
            // BEDA predikat dari `team` di bawah: itu `entity=jmto` (siapa saja JMTO,
            // termasuk MCS), ini `role=field_officer` khusus — SK cuma mengenal
            // Petugas Layanan Jalan Tol sebagai penanda tangan slot petugas, MCS
            // menandatangani lewat aksi Sahkan-nya sendiri, bukan lewat slot ini.
            // TIDAK dibatasi ke `team`: petugas boleh menunjuk siapa pun field_officer
            // aktif walau tidak hadir di TKP — itu yang menutup kasus regu 1 orang.
            'officer_2_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where(User::petugasLapangan()),
            ],

            // Regu yang bertugas — array user_id. Unique index di operational_team
            // menolak duplikat, jadi 'distinct' mencegahnya jadi 500.
            //
            // Dibatasi JMTO aktif, PERSIS seperti daftar `officers` di sync/bootstrap:
            // regu insiden itu petugas JMTO yang hadir di TKP. Tanpa batas ini server
            // menerima id siapa pun yang ada di `users` — termasuk Unit Maintenance
            // JMJ yang tidak pernah ke TKP, dan pegawai yang sudah nonaktif. Daftar di
            // HP memang sudah tersaring, tapi daftar di HP bukan penjaga: yang mengirim
            // request itu APK di HP orang.
            'team' => ['array'],
            'team.*' => [
                'integer', 'distinct',
                Rule::exists('users', 'id')->where('entity', 'jmto')->where('is_active', true),
            ],

            'vehicles' => ['array'],
            'vehicles.*.vehicle_type' => ['required', Rule::in(config('enums.vehicle_type'))],
            'vehicles.*.vehicle_group' => ['nullable', Rule::in(config('enums.vehicle_group'))],
            'vehicles.*.plate_number' => ['required', 'string', 'max:15'],

            'culprits' => ['array'],
            // Kendaraan yang dikemudikan pelaku ini, dirujuk lewat POSISI di
            // `vehicles[]` kiriman yang sama — bukan id.
            //
            // `culprit.involved_vehicle_id` itu bigint yang lahir di server, jadi HP
            // yang menyusun laporan luring tidak punya apa pun untuk dituliskan.
            // Posisi array aman karena kedua daftar datang dalam satu request: server
            // menyisipkan kendaraannya dulu, lalu menerjemahkan indeks jadi id.
            //
            // Di layar, kartu "Pihak N" mengisi vehicles[N] dan culprits[N] sekaligus,
            // jadi nilainya biasanya sama dengan nomor kartunya sendiri.
            'culprits.*.vehicle_index' => ['nullable', 'integer', 'min:0'],
            'culprits.*.full_name' => ['required', 'string', 'max:120'],
            // "Dalam hal ini disebut sebagai pemilik / pengemudi / pengurus" di
            // formulir BA Kerusakan. Nullable dengan alasan yang sama seperti
            // location_type, plus: yang lama tercetak titik-titik dan diisi tangan.
            'culprits.*.party_role' => ['nullable', Rule::in(config('enums.party_role'))],
            'culprits.*.national_id' => ['nullable', 'string', 'size:16'],
            'culprits.*.phone_number' => ['nullable', 'string', 'max:20'],
            'culprits.*.address' => ['nullable', 'string'],
            'culprits.*.age' => ['nullable', 'integer', 'min:0', 'max:150'],
            'culprits.*.occupation' => ['nullable', 'string', 'max:80'],
            'culprits.*.driver_license_number' => ['nullable', 'string', 'max:20'],

            'damaged_items' => ['array'],
            'damaged_items.*.asset_catalog_id' => ['required', 'integer', 'exists:asset_catalog,id'],
            'damaged_items.*.catalog_price_id' => ['required', 'integer', 'exists:catalog_prices,id'],
            'damaged_items.*.quantity' => ['required', 'numeric', 'gt:0'],
            // gt:0, bukan between:0,100 — 0% berarti asetnya TIDAK rusak, dan barisnya
            // tidak punya alasan ada. Yang lolos senilai Rp 0 tetap masuk antrean Work
            // Order (`work_order_id IS NULL`), jadi regu perbaikan dikirim ke komponen
            // yang tidak apa-apa. Sama alasannya dengan quantity yang sudah gt:0.
            'damaged_items.*.damage_percentage' => ['required', 'numeric', 'gt:0', 'max:100'],
        ];
    }

    /**
     * Dua percabangan di Fase 1 punya penandanya sendiri. Kalau penanda dan isinya
     * bertentangan, yang salah bukan salah satunya — kita tidak tahu yang mana,
     * jadi ditolak daripada menebak.
     *
     * Percabangan pertama sekarang dipegang `analyze`, bukan `is_asset_broken`:
     * boolean-nya sudah tidak diterima dari HP, server yang menurunkannya.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Pelapor otomatis penanda tangan 1 (`user_id`) — dia tidak boleh
                // menunjuk dirinya sendiri lagi buat slot 2. `different:user_id` tidak
                // bisa dipakai di rules(): user_id tidak datang dari body, jadi rule
                // itu tidak punya apa pun untuk dibandingkan.
                if ((int) $this->input('officer_2_id') === $this->user()->id) {
                    $validator->errors()->add('officer_2_id', 'Penanda tangan kedua harus petugas lain, bukan diri sendiri.');
                }

                if ($this->input('analyze') === 'Tidak Ada Kerusakan Aset' && filled($this->input('damaged_items'))) {
                    $validator->errors()->add('damaged_items', 'Analisa menyatakan tidak ada kerusakan aset tapi daftar kerusakan terisi.');
                }

                // ARAH SEBALIKNYA, dan ini yang lebih mahal kalau bocor. `analyze`
                // selain "Tidak Ada Kerusakan Aset" menurunkan is_asset_broken = true,
                // yang berarti BA Kerusakan WAJIB terbit. Dengan nol baris kerusakan,
                // yang terbit BA resmi bernomor bertabel kosong senilai Rp 0 dan
                // terbilang "nol rupiah" — dan yang lebih senyap: antrean Work Order
                // itu `damaged_items.work_order_id IS NULL`, jadi aset yang dinyatakan
                // Rusak Berat tidak pernah muncul di daftar kerja siapa pun.
                if ($this->input('analyze') !== null
                    && $this->input('analyze') !== 'Tidak Ada Kerusakan Aset'
                    && blank($this->input('damaged_items'))) {
                    $validator->errors()->add('damaged_items', 'Analisa menyatakan ada kerusakan aset tapi daftar kerusakan kosong.');
                }

                if ($this->boolean('is_identified') === false && filled($this->input('culprits'))) {
                    $validator->errors()->add('culprits', 'Ada data pelaku tapi is_identified bernilai false.');
                }

                if ($this->boolean('is_identified') === true && blank($this->input('culprits'))) {
                    $validator->errors()->add('culprits', 'is_identified bernilai true tapi data pelaku kosong.');
                }

                // Pelaku teridentifikasi WAJIB disertai minimal satu kendaraan.
                // Formulir BA Kerusakan menulis "kendaraan jenis ...... no polisi ......"
                // sebagai data PIHAK KESATU (SK hal. 19); tanpa satu pun baris
                // involved_vehicles, dua slot itu tercetak kosong di dokumen yang
                // dipakai menagih — padahal kendaraan itulah yang ditahan Jasa Marga
                // sampai ganti ruginya beres.
                //
                // Yang diwajibkan KENDARAANNYA ADA, bukan `culprits.0.vehicle_index`
                // terisi. Dua fakta yang berbeda: kolom itu berarti "kendaraan yang
                // DIKEMUDIKAN pelaku ini", dan ERD memang membolehkannya null untuk
                // pemilik yang tidak ikut menyetir. Yang diminta formulir kendaraan
                // yang TERLIBAT — BaPdfService yang jatuh ke kendaraan pertama laporan
                // kalau tautannya tidak ada.
                //
                // Tabrak lari tidak kena sama sekali: is_identified false.
                if ($this->boolean('is_identified') === true && blank($this->input('vehicles'))) {
                    $validator->errors()->add(
                        'vehicles',
                        'Pelaku teridentifikasi tapi tidak ada kendaraan yang dicatat — jenis dan nomor polisinya tercetak di BA Kerusakan.',
                    );
                }

                // Indeks harus menunjuk kendaraan yang benar-benar ada di kiriman ini.
                // Kalau lolos, service mengambil $vehicles[$i] yang tidak ada dan
                // laporannya mati 500 — bukan 422 yang bisa dibaca petugas.
                $count = count((array) $this->input('vehicles', []));

                foreach ((array) $this->input('culprits', []) as $i => $culprit) {
                    $ref = $culprit['vehicle_index'] ?? null;

                    if ($ref !== null && $ref >= $count) {
                        $validator->errors()->add(
                            "culprits.{$i}.vehicle_index",
                            'Kendaraan yang dirujuk tidak ada di daftar kendaraan laporan ini.',
                        );
                    }
                }
            },
        ];
    }
}
