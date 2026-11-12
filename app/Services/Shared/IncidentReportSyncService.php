<?php

namespace App\Services\Shared;

use App\Exceptions\DomainException;
use App\Models\ActivityLog;
use App\Models\CatalogPrice;
use App\Models\IncidentReport;
use App\Models\User;
use App\Notifications\IncidentReportNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class IncidentReportSyncService
{
    /**
     * Kolom induk yang boleh datang dari HP. Sisanya ditentukan server.
     *
     * `is_asset_broken` sengaja TIDAK ada di sini — diturunkan dari `analyze`
     * di bawah. Kalau HP tetap mengirimnya, nilainya dibuang di sini.
     */
    private const CLIENT_FIELDS = [
        'date_time', 'km', 'm', 'lane', 'route', 'location_type', 'weather_condition',
        'causer', 'causer_other', 'incident_explanation', 'action',
        'analyze', 'conclusion', 'is_identified', 'additional_cost',
        'settlement_type', 'relief_requested', 'pledged_amount', 'officer_2_id',
        'latitude', 'longitude',
    ];

    /**
     * Upsert satu laporan utuh, idempoten terhadap uuid buatan HP.
     *
     * Kirim ulang karena sinyal putus menghasilkan keadaan yang sama, bukan laporan
     * kedua. Baris anak diganti seisi-isinya — aman justru karena dokumen nempel ke
     * uuid laporan, bukan ke id baris anak yang ikut berganti di sini.
     */
    public function upsert(string $uuid, User $officer, array $data): IncidentReport
    {
        return DB::transaction(function () use ($uuid, $officer, $data) {
            $report = IncidentReport::lockForUpdate()->find($uuid);

            if ($report && ! $report->isEditable()) {
                throw new DomainException(
                    'Laporan sudah disahkan dan tidak bisa diubah lagi.',
                    'report_status',
                );
            }

            // DIAMBIL SEBELUM updateOrCreate — sesudahnya sudah tertimpa, dan jam
            // yang diklaim sebelumnya tidak punya kolom mana pun yang menampungnya.
            $jamLama = $report?->date_time;
            $dikirimUlang = $report?->report_status === 'rejected';

            $items = $this->price($data['damaged_items'] ?? []);
            // Dibulatkan dengan alasan yang sama dengan subtotal di price(): dia
            // dijumlahkan ke total_estimation, jadi sen yang lolos di sini muncul
            // lagi sebagai selisih angka-vs-huruf di BA.
            $additionalCost = round((float) ($data['additional_cost'] ?? 0));

            $report = IncidentReport::updateOrCreate(['uuid' => $uuid], array_merge(
                collect($data)->only(self::CLIENT_FIELDS)->all(),
                [
                    // Pembuat tidak berpindah tangan walau yang mengirim ulang orang lain.
                    'user_id' => $report?->user_id ?? $officer->id,
                    // Satu fakta satu sumber. Kolomnya tetap ada — cuma pengisinya
                    // pindah ke server, karena "Tidak Ada Kerusakan Aset" di layar
                    // Analisa Kerusakan menjawab pertanyaan yang sama persis.
                    //
                    // Ini SYARAT, bukan pemicu: sinkronisasi Fase 1 tidak menerbitkan
                    // BA apa pun. MCS yang membacanya di Fase 2.1 untuk memutuskan
                    // perlu tidaknya damage_ba diterbitkan sama sekali.
                    'is_asset_broken' => $data['analyze'] !== 'Tidak Ada Kerusakan Aset',
                    'additional_cost' => $additionalCost,
                    'total_estimation' => array_sum(array_column($items, 'subtotal')) + $additionalCost,
                    'sync_status' => 'sent',
                    // Laporan yang ditolak lalu dibetulkan masuk antrean MCS lagi.
                    'report_status' => 'pending_verification',
                ],
            ));

            $report->operationalTeam()->delete();
            // Pelaku duluan, baru kendaraannya: culprit.involved_vehicle_id menunjuk
            // involved_vehicles, dan urutan terbalik memicu UPDATE nullOnDelete pada
            // baris yang toh sedetik lagi ikut dihapus.
            $report->culprits()->delete();
            $report->involvedVehicles()->delete();
            $report->damagedItems()->delete();

            $report->operationalTeam()->createMany(
                collect($data['team'] ?? [])->map(fn ($id) => ['user_id' => $id])->all()
            );
            // Kendaraan duluan supaya id-nya sudah ada waktu pelaku menunjuknya.
            // createMany mengembalikan koleksi berurutan sama dengan masukannya —
            // itu yang membuat `vehicle_index` bisa diterjemahkan.
            $vehicles = $report->involvedVehicles()->createMany($data['vehicles'] ?? []);

            $report->culprits()->createMany(
                collect($data['culprits'] ?? [])->map(function (array $c) use ($vehicles) {
                    $index = $c['vehicle_index'] ?? null;
                    // Bukan kolom — cuma cara HP merujuk kendaraan tanpa punya id-nya.
                    unset($c['vehicle_index']);

                    return $c + [
                        'involved_vehicle_id' => $index === null ? null : $vehicles->get($index)?->id,
                        'created_at' => now(),
                    ];
                })->all()
            );
            $report->damagedItems()->createMany($items);

            // HANYA kalau BERUBAH, dan hanya pada laporan yang sudah ada. Sync pertama
            // tidak dicatat: user_id + created_at + date_time sudah menyimpan siapa,
            // kapan mengirim, dan jam berapa yang diklaim — baris log di situ murni
            // duplikat, dan ActivityLog::record() melarangnya justru karena duplikat
            // itu yang belakangan melenceng.
            //
            // Yang benar-benar hilang tanpa ini: jam yang diklaim SEBELUMNYA. Laporan
            // yang ditolak lalu dikirim ulang dengan date_time berbeda menimpa nilai
            // lama tanpa bekas — dan itu persis manuver yang perlu bisa ditelusuri.
            //
            // equalTo(), bukan !== : dua objek Carbon bernilai sama tidak pernah identik.
            if ($jamLama && ! $jamLama->equalTo($report->date_time)) {
                ActivityLog::record($report, 'incident_time_changed', $officer,
                    ['date_time' => $jamLama->toDateTimeString()],
                    ['date_time' => $report->date_time->toDateTimeString()],
                    'Jam kejadian diubah petugas saat mengirim ulang laporan.');
            }

            // Cuma laporan yang DITOLAK lalu dibetulkan. Kiriman pertama dan kirim ulang
            // biasa (sinyal putus) tidak — antrean MCS sendiri sudah menampilkannya; yang
            // tidak terlihat dari antrean adalah bahwa baris itu revisi atas penolakannya.
            if ($dikirimUlang) {
                Notification::send(
                    User::where('role', 'mcs_supervisor')->where('is_active', true)->get(),
                    new IncidentReportNotification($report, IncidentReportNotification::RESUBMITTED,
                        ucfirst(IncidentReportNotification::label($report))." yang ditolak sudah diperbaiki {$officer->name}, menunggu verifikasi lagi."),
                );
            }

            return $report;
        });
    }

    /**
     * Menghitung ulang subtotal. `subtotal` kiriman HP diabaikan — uang tidak pernah
     * dipercayakan ke client.
     *
     * PUBLIC, dan itu disengaja: IncidentVerificationService::correct() memanggilnya
     * waktu MCS membetulkan quantity. Disalin ke sana, dua rumah rumus rupiah akan
     * melenceng — dan yang ikut hilang bukan cuma perkaliannya, tapi dua penjaga yang
     * ada di sini: harga yang bukan milik asetnya, dan max_price yang masih NULL.
     * Koreksi MCS harus gagal di tempat yang sama dengan kiriman HP.
     *
     * Tapi `catalog_price_id` kiriman HP DIPAKAI, bukan dicari ulang: kolom itu ada
     * persis untuk mengunci versi harga. Harga direvisi selagi HP luring tidak boleh
     * mengubah angka yang sudah dilihat dan disepakati petugas di lokasi.
     *
     * @throws DomainException harga tidak cocok dengan asetnya, atau belum di-seed
     */
    public function price(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $prices = CatalogPrice::whereIn('id', array_column($rows, 'catalog_price_id'))
            ->get()->keyBy('id');

        return collect($rows)->map(function (array $row) use ($prices) {
            $price = $prices->get($row['catalog_price_id']);

            if ((int) $price->asset_catalog_id !== (int) $row['asset_catalog_id']) {
                throw new DomainException(
                    "Harga #{$row['catalog_price_id']} bukan milik aset #{$row['asset_catalog_id']}.",
                    'damaged_items',
                );
            }

            // Perhitungan memakai max_price (keputusan 31 Ags), dan itu tetap begitu
            // setelah 6,5% ternyata inflasi: MINIMAL harga kontrak 2020, MAKSIMAL
            // harga itu hari ini. `price` di-seed sama dengan max_price sejak 3 Sep,
            // tapi yang DIBACA tetap max_price — satu kolom, satu sumber angka.
            // Gagal berisik di sini jauh lebih baik daripada BA yang meleset 6,5%.
            if ($price->max_price === null) {
                throw new DomainException(
                    "Aset #{$row['asset_catalog_id']} belum punya harga di katalog.",
                    'damaged_items',
                );
            }

            return [
                'asset_catalog_id' => $row['asset_catalog_id'],
                'catalog_price_id' => $row['catalog_price_id'],
                'quantity' => $row['quantity'],
                'damage_percentage' => $row['damage_percentage'],
                // damage_percentage itu potongan tingkat kerusakan, bukan volume.
                //
                // DIBULATKAN KE RUPIAH UTUH, dan ini satu-satunya tempat sen bisa
                // lahir. MAKSIMAL Lampiran I sering berakhir ,50 (356.029,50), jadi
                // subtotal ikut berakhir ,50 — lalu total, lalu damage_ba.total_amount.
                // Sementara `terbilang()` membulatkan ke rupiah utuh, jadi ANGKA dan
                // HURUF di BA Kerusakan berbeda 50 sen. Di dokumen bertanda tangan yang
                // jadi dasar tagihan, itu bahan sengketa.
                //
                // Dibulatkan di sini, bukan di totalnya: kolom JUMLAH HARGA di formulir
                // BA harus menjumlah TEPAT ke TOTAL. Kalau yang dibulatkan totalnya,
                // penjumlahan kolom meleset dan yang menemukannya orang yang memegang
                // kertasnya. Rupiah Indonesia memang tidak mengenal sen.
                //
                // ponytail: float biasa. Rupiah bulat jauh di dalam presisi float64;
                // ganti ke bcmath kalau nanti ada mata uang lain.
                'subtotal' => round(
                    (float) $price->max_price * (float) $row['quantity'] * (float) $row['damage_percentage'] / 100,
                ),
            ];
        })->all();
    }
}
