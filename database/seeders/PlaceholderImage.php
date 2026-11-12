<?php

namespace Database\Seeders;

/**
 * PNG contoh bertuliskan labelnya, supaya FE bisa membedakan foto di layar.
 *
 * Dipakai IncidentSeeder (foto TKP, KTP, tanda tangan) dan WorkOrderSeeder (bukti
 * Before-During-After). Ditulis di satu tempat bukan karena kodenya rumit, tapi karena
 * dua seeder yang menghasilkan gambar dengan bentuk berbeda membuat layar yang memuat
 * keduanya terlihat seperti ada yang rusak.
 */
class PlaceholderImage
{
    public static function png(string $label): string
    {
        $image = imagecreatetruecolor(640, 360);
        imagefill($image, 0, 0, imagecolorallocate($image, 232, 236, 241));
        imagerectangle($image, 8, 8, 631, 351, imagecolorallocate($image, 150, 160, 175));

        $ink = imagecolorallocate($image, 40, 48, 60);
        // Font bawaan GD, bukan TTF: tidak ada berkas font di repo dan tidak perlu ada.
        imagestring($image, 5, 24, 160, $label, $ink);
        imagestring($image, 3, 24, 190, 'Contoh hasil seeder - bukan foto sungguhan', $ink);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
