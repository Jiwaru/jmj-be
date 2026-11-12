<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Pencarian teks bebas dan ukuran halaman untuk daftar berpaginasi — satu tempat,
 * dipakai katalog aset dan antrean patroli.
 */
class Search
{
    /**
     * Pola `ilike` "mengandung" dari `?search=`, atau null kalau kosong/bukan teks.
     *
     * `%`, `_`, dan `\` di kata kunci di-escape: tanpa itu "100%" atau "PJU_1" berubah
     * jadi wildcard. Bukan teks (`?search[]=x`) diabaikan, bukan 500.
     */
    public static function pattern(mixed $term): ?string
    {
        return is_string($term) && trim($term) !== ''
            ? '%'.addcslashes(trim($term), '%_\\').'%'
            : null;
    }

    /** `per_page` dari klien, dijepit 1..100. Tanpa batas, `per_page=100000` = dump tabel. */
    public static function perPage(Request $request, int $default = 20): int
    {
        return max(1, min(100, $request->integer('per_page', $default)));
    }
}
