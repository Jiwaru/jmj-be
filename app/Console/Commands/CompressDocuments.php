<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Support\ImageCompressor;
use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;

/**
 * Sekali jalan untuk foto yang terlanjur tersimpan mentah sebelum kompresi dipasang
 * di DocumentStorageService. Unggahan baru tidak butuh ini.
 *
 * Kompresi TIDAK BISA DIBALIK — backup storage/app/private ke luar server dulu.
 * Langkah lengkap di server: docs/RENCANA-KOMPRESI-FOTO.md §5.
 *
 * Dijalankan dengan memori lebih besar, karena batas piksel unggahan dimatikan di sini
 * (foto lama boleh raksasa):
 *   php -d memory_limit=512M artisan documents:compress --dry-run
 */
class CompressDocuments extends Command
{
    protected $signature = 'documents:compress {--dry-run : Hitung saja, jangan ubah apa pun}';

    protected $description = 'Kompres foto lama di tabel documents yang sisi terpanjangnya melebihi '.ImageCompressor::MAX_SIDE.' px';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $dryRun = (bool) $this->option('dry-run');
        $jumlah = ['diproses' => 0, 'dilewati' => 0, 'hilang' => 0, 'bentrok' => 0];
        $sebelum = 0;
        $sesudah = 0;

        // PDF Berita Acara tidak ikut: lokasinya incident_ba/damage_ba.pdf_path, bukan
        // tabel ini. PDF surat keringanan tersaring oleh mime_type.
        Document::whereIn('mime_type', ['image/jpeg', 'image/png'])->chunkById(100, function ($dokumen) use ($disk, $dryRun, &$jumlah, &$sebelum, &$sesudah) {
            foreach ($dokumen as $doc) {
                if (! $disk->exists($doc->path)) {
                    $this->warn("Berkas hilang dari disk, dilewati: #{$doc->id} {$doc->path}");
                    $jumlah['hilang']++;

                    continue;
                }

                $asli = $disk->path($doc->path);
                $ukuran = @getimagesize($asli);

                // Yang sudah kecil dilewati — jadi command ini aman diulang.
                if ($ukuran === false || max($ukuran[0], $ukuran[1]) <= ImageCompressor::MAX_SIDE) {
                    $jumlah['dilewati']++;

                    continue;
                }

                $pathBaru = preg_replace('/\.[^.\/]+$/', '', $doc->path).'.jpg';

                // Kasus RENCANA §3.4: PNG lama dikirim ulang sesudah deploy sudah
                // melahirkan baris .jpg sendiri. Unique documents.path — jangan ditimpa.
                if ($pathBaru !== $doc->path && Document::where('path', $pathBaru)->exists()) {
                    $this->warn("Path tujuan sudah dipakai baris lain, dilewati: #{$doc->id} {$pathBaru}");
                    $jumlah['bentrok']++;

                    continue;
                }

                $jumlah['diproses']++;
                $sebelum += filesize($asli);

                if ($dryRun) {
                    continue;
                }

                $pathLama = $doc->path;
                $jpeg = ImageCompressor::compress($asli, maxPixels: null);

                try {
                    $disk->putFileAs(dirname($pathBaru), new File($jpeg), basename($pathBaru));
                    $sesudah += filesize($jpeg);

                    $doc->update([
                        'path' => $pathBaru,
                        'mime_type' => 'image/jpeg',
                        'size_bytes' => filesize($jpeg),
                    ]);
                } finally {
                    @unlink($jpeg);
                }

                // SETELAH barisnya menunjuk berkas baru: berhenti di tengah tidak
                // meninggalkan baris yang menunjuk berkas yang sudah dihapus.
                if ($pathBaru !== $pathLama) {
                    $disk->delete($pathLama);
                }
            }
        });

        $mb = fn (int $byte) => number_format($byte / 1048576, 1, ',', '.').' MB';

        $this->info(($dryRun ? '[DRY RUN] ' : '')."Diproses: {$jumlah['diproses']} foto, {$mb($sebelum)}"
            .($dryRun ? ' (perkiraan hasil ~0,3 MB per foto)' : " → {$mb($sesudah)}"));
        $this->line("Dilewati (sudah kecil/bukan foto): {$jumlah['dilewati']} · hilang dari disk: {$jumlah['hilang']} · bentrok path: {$jumlah['bentrok']}");

        return self::SUCCESS;
    }
}
