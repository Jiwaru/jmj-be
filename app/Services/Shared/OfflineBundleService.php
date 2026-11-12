<?php

namespace App\Services\Shared;

use App\Models\AssetCatalog;
use App\Models\AssetType;
use App\Models\CatalogPrice;
use App\Models\User;

/**
 * Bekal offline Fase 0. Satu tarikan, satu snapshot konsisten — kalau dipecah jadi
 * tiga request, HP bisa dapat katalog versi baru dengan harga versi lama.
 *
 * Ukurannya kecil (Lampiran I puluhan baris), jadi belum perlu delta `?since=`.
 */
class OfflineBundleService
{
    public function forFieldOfficer(): array
    {
        return [
            // Tingkat 1 (kategori) tidak perlu query — nilainya ada di kolom category.
            'asset_types' => AssetType::orderBy('category')->orderBy('name')
                ->get(['id', 'category', 'name', 'standard_photo_id', 'description']),

            'asset_catalog' => AssetCatalog::orderBy('asset_type_id')->orderBy('component')
                ->get(['id', 'asset_type_id', 'name', 'component', 'unit_of_measurement', 'standard_photo_id', 'specification']),

            // Hanya harga yang masih berlaku. Baris lama tetap ada di server supaya
            // damaged_items historis tidak kehilangan acuannya, tapi HP tidak perlu.
            'catalog_prices' => CatalogPrice::whereNull('effective_to')
                ->get(['id', 'asset_catalog_id', 'price', 'min_price', 'max_price', 'effective_from']),

            // Daftar rekan untuk mengisi operational_team. Dibatasi JMTO aktif —
            // regu insiden itu petugas JMTO. position sengaja tidak ikut.
            'officers' => User::where('is_active', true)->where('entity', 'jmto')
                ->orderBy('name')->get(['id', 'identity_code', 'name']),

            // Calon penanda tangan 2 BA Kejadian (redesain 16 Sep). BEDA dari
            // `officers` di atas — itu regu TKP (entity jmto, boleh siapa saja
            // termasuk MCS), ini KHUSUS field_officer aktif: BA cuma boleh diteken
            // Petugas Layanan Jalan Tol (SK), bukan MCS.
            'ba_officers' => User::where(User::petugasLapangan())
                ->orderBy('name')->get(['id', 'identity_code', 'name']),

            // Isi setiap dropdown dan checkbox di form insiden. Ikut ke sini, bukan
            // endpoint sendiri, karena petugas membuka formnya di bahu jalan tanpa
            // sinyal — dropdown yang isinya baru datang setelah request itu dropdown
            // kosong, dan formnya tidak bisa diisi sama sekali.
            //
            // Datang dari server dan bukan di-hardcode HP karena daftar yang sama ini
            // juga yang menyusun CHECK constraint. Salinan di HP bisa melenceng dari
            // CHECK, dan gejalanya 422 di lapangan — tempat paling buruk untuk ketahuan.
            //
            // Nilainya dipakai apa adanya sebagai label: sudah bahasa Indonesia siap
            // tampil, jadi HP tidak perlu tabel pemetaan.
            //
            // DISARING, bukan config('enums') utuh. Sejak Fase 4 file itu juga memuat
            // daftar milik Work Order (`wo_*`) yang value-nya Inggris dan layarnya cuma
            // ada di web Unit Maintenance. Terkirim apa adanya, bundel luring HP petugas
            // membawa dua daftar yang tidak pernah dia pakai — dan begitu ada daftar
            // ke-15, tidak ada yang menyadarinya ikut terbawa juga.
            'options' => collect(config('enums'))
                ->reject(fn ($_, string $key) => str_starts_with($key, 'wo_'))
                ->all(),
        ];
    }

    /**
     * Bekal luring Petugas Maintenance (Fase 5). BENTUKNYA BEDA JAUH dari milik Petugas
     * Patroli, dan itu bukan kebetulan.
     *
     * Bundel patroli isinya DATA ACUAN — katalog, harga, daftar pilihan. Jarang berubah,
     * sama untuk semua orang, dan sekali diunduh berlaku berhari-hari. Petugas maintenance
     * tidak menghitung uang sama sekali: biaya perbaikan dihitung Fase 6 dari
     * `damaged_items` yang sudah ada di server. Mengirim Lampiran I dan daftar harga ke
     * HP-nya berarti mengunduh puluhan baris yang tidak pernah dia buka.
     *
     * Yang berubah tiap hari dan beda per orang — daftar Work Order yang ditugaskan
     * kepadanya — SENGAJA TIDAK ADA DI SINI. Tempatnya `GET /work-orders`, yang sudah
     * berpaginasi dan sudah disaring `scopeVisibleTo`. Menaruhnya di bundel berarti satu
     * response yang membengkak tiap kali pekerjaannya bertambah, tanpa cara mengambil
     * halaman berikutnya.
     *
     * `officers` juga tidak ikut: itu daftar rekan JMTO untuk mengisi regu INSIDEN. Regu
     * perbaikan ditunjuk Unit Maintenance dari web, bukan disusun petugas di lapangan.
     */
    public function forMaintenanceOfficer(): array
    {
        return [
            // HANYA daftar `wo_*`, kebalikan persis dari saringan di atas. Kelimanya
            // mengisi dropdown dan checkbox di layar Fase 5, dan datang dari server
            // karena daftar yang SAMA ini juga yang menyusun CHECK constraint. Salinan
            // yang di-hardcode di HP bisa melenceng dari CHECK, dan gejalanya 422 di
            // bahu jalan — tempat paling buruk untuk ketahuan.
            //
            // KONTRAK `options`: grup → DAFTAR (array list). FE (replaceOptions) memanggil
            // .map() ke tiap grup; satu grup berbentuk objek membuat SEMUA pilihan gagal
            // tersimpan, dan gerbang APD tidak bisa dilewati (insiden 2 Okt). Peta yang
            // bukan daftar dikeluarkan ke kunci sendiri di bawah.
            'options' => collect(config('enums'))
                ->filter(fn ($v, string $key) => str_starts_with($key, 'wo_') && array_is_list($v))
                ->all(),

            // Keterangan di bawah tiap pilihan dropdown alasan: nama alasan → kalimat.
            'option_descriptions' => config('enums.wo_reason_descriptions'),
        ];
    }
}
