<?php

namespace App\Support;

use App\Exceptions\DomainException;

/**
 * Mengecilkan foto sebelum disimpan — satu tempat, dipakai DocumentStorageService
 * (unggahan baru) dan command documents:compress (foto lama yang terlanjur mentah).
 *
 * Server produksi cuma 30 GB disk dan 2 GB RAM, sementara foto HP mentah 3–8 MB.
 * FE juga mengompres dengan angka yang SAMA, tapi klien tidak bisa dipercaya
 * (aplikasi versi lama, form yang lupa) — yang menjamin isi disk ya di sini.
 * Rencana lengkapnya: docs/RENCANA-KOMPRESI-FOTO.md.
 */
final class ImageCompressor
{
    /** Sisi terpanjang. NIK di foto KTP masih terbaca di ukuran ini. */
    public const MAX_SIDE = 1600;

    public const QUALITY = 75;

    /**
     * GD membuka SELURUH gambar di RAM, kira-kira 5 byte per piksel: 25 MP ≈ 125 MB,
     * mepet `memory_limit` 128M. Foto 50 MP (mode resolusi tinggi HP kelas atas) akan
     * jadi 500, jadi ditolak lebih dulu dengan pesan. Command backfill berjalan di CLI
     * dengan memori lebih besar dan mematikan batas ini.
     */
    public const MAX_PIXELS = 25_000_000;

    /**
     * Path JPEG sementara hasil kompresi — pemanggil yang memindahkan lalu menghapusnya.
     * Null kalau bukan JPEG/PNG (PDF dll.): simpan berkas aslinya apa adanya.
     *
     * Selalu di-encode ulang, juga foto yang sudah kecil: sekalian membuang EXIF, yang
     * di foto KTP bisa memuat GPS rumah orang.
     *
     * @throws DomainException gambarnya lebih besar dari $maxPixels
     */
    public static function compress(string $source, ?int $maxPixels = self::MAX_PIXELS): ?string
    {
        // Dibaca dari header saja — belum ada satu piksel pun yang dimuat ke RAM.
        $info = @getimagesize($source);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return null;
        }

        [$lebar, $tinggi, $jenis] = $info;
        if ($maxPixels !== null && $lebar * $tinggi > $maxPixels) {
            throw new DomainException(
                'Foto terlalu besar untuk diproses. Kirim ulang dari aplikasi versi terbaru.',
                'file',
            );
        }

        $gambar = $jenis === IMAGETYPE_JPEG ? @imagecreatefromjpeg($source) : @imagecreatefrompng($source);
        if ($gambar === false) {
            return null;
        }

        if ($jenis === IMAGETYPE_JPEG) {
            $gambar = self::tegakkan($gambar, $source);
        }

        // Ukuran diambil SETELAH diputar: foto potret yang diputar 90° bertukar sisi.
        $lebar = imagesx($gambar);
        $tinggi = imagesy($gambar);
        $skala = min(1, self::MAX_SIDE / max($lebar, $tinggi));
        $lebarBaru = max(1, (int) round($lebar * $skala));
        $tinggiBaru = max(1, (int) round($tinggi * $skala));

        // Kanvas putih: bagian transparan PNG jadi hitam kalau langsung disimpan JPEG.
        // imagecopyresampled sekaligus mengecilkan, jadi satu langkah untuk dua urusan.
        $hasil = imagecreatetruecolor($lebarBaru, $tinggiBaru);
        imagefill($hasil, 0, 0, imagecolorallocate($hasil, 255, 255, 255));
        imagecopyresampled($hasil, $gambar, 0, 0, 0, 0, $lebarBaru, $tinggiBaru, $lebar, $tinggi);
        imagedestroy($gambar);

        $tujuan = tempnam(sys_get_temp_dir(), 'jmj-foto-');
        imagejpeg($hasil, $tujuan, self::QUALITY);
        imagedestroy($hasil);

        return $tujuan;
    }

    /**
     * Foto HP tidak memutar pikselnya — dia cuma menulis arah putar di EXIF, dan GD
     * membuang EXIF waktu encode ulang. Tanpa ini foto potret tersimpan miring.
     *
     * Ekstensi exif belum tentu terpasang (dipasang lewat Dockerfile, butuh rebuild):
     * tanpanya foto tetap tersimpan, mungkin miring, tapi tidak jadi 500.
     */
    private static function tegakkan(\GdImage $gambar, string $source): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $gambar;
        }

        // false kalau JPEG-nya tanpa EXIF — dan Laravel mengubah warning "offset pada
        // bool" jadi exception, jadi dicek dulu, bukan langsung diindeks.
        $exif = @exif_read_data($source);
        $arah = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        // imagerotate memutar BERLAWANAN jarum jam.
        $sudut = match ($arah) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($sudut === 0) {
            return $gambar;
        }

        $diputar = imagerotate($gambar, $sudut, 0);
        imagedestroy($gambar);

        return $diputar;
    }
}
