<?php

namespace App\Services\Shared;

use App\Exceptions\DomainException;
use App\Models\ActivityLog;
use App\Models\AssetCatalog;
use App\Models\AssetType;
use App\Models\Document;
use App\Models\IncidentReport;
use App\Models\PatrolReport;
use App\Models\User;
use App\Models\WorkOrderLog;
use App\Support\ImageCompressor;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Menyimpan berkas unggahan dan barisnya di `documents`.
 *
 * Disk 'local' (storage/app/private), BUKAN 'public' — folder ini berisi foto KTP
 * dan NIK pihak ketiga, dan disk public di-symlink ke webroot sehingga siapa pun
 * yang tahu URL-nya dapat filenya tanpa login.
 */
class DocumentStorageService
{
    /**
     * Menghapus berkas beserta barisnya — untuk salah unggah.
     *
     * Dibatasi ke laporan yang belum disahkan. Setelah MCS mengesahkan, foto TKP dan
     * tanda tangan sudah jadi bagian dari Berita Acara yang diteken; menghapusnya
     * berarti membuang lampiran dokumen resmi.
     *
     * Meninggalkan jejak di `activity_logs`, dan penghapusnya ikut diterima justru
     * untuk itu — barisnya sendiri yang hilang, jadi tidak ada kolom yang tersisa
     * untuk menyimpan siapa.
     *
     * @throws DomainException laporannya sudah disahkan
     */
    public function delete(Document $document, User $penghapus): void
    {
        // Dua subjek yang bisa punya dokumen "salah unggah" — insiden dan patroli.
        // Bukan cabang ketiga di sini, cuma dua Model yang sama-sama punya
        // isEditable(); pesannya beda karena "disahkan" (BA) tidak berlaku untuk
        // patroli yang memang tidak punya BA sama sekali.
        if ($document->documentable_type === 'patrol_report') {
            $report = PatrolReport::findOrFail($document->documentable_id);
            $pesan = 'Laporan sudah ditindaklanjuti Unit Maintenance, dokumennya tidak bisa dihapus lagi.';
        } else {
            $report = IncidentReport::findOrFail($document->documentable_id);
            $pesan = 'Laporan sudah disahkan, dokumennya tidak bisa dihapus lagi.';
        }

        if (! $report->isEditable()) {
            throw new DomainException($pesan, 'report_status');
        }

        // SEBELUM barisnya dihapus. Ini satu-satunya aksi di Fase 0-2 yang tidak
        // meninggalkan apa pun, dan dia lolos aturan ActivityLog::record() dengan
        // telak: "yang masuk sini justru yang TIDAK punya kolom" — di sini bahkan
        // barisnya sendiri yang hilang, jadi tidak ada kolom mana pun yang bisa
        // menampung faktanya.
        //
        // Skenarionya: petugas mengunggah foto yang memperlihatkan sesuatu yang tidak
        // menguntungkan, lalu menghapusnya sebelum MCS melihat. Tanpa baris ini sistem
        // tidak pernah tahu foto itu pernah ada.
        //
        // Subjeknya LAPORAN, bukan dokumennya: auditable_id yang menunjuk baris yang
        // sudah tidak ada tidak bisa ditelusuri siapa pun. Dan old_values terisi dengan
        // new_values null — bentuk yang benar untuk penghapusan, sejajar dengan
        // `correction` yang mengisi keduanya.
        ActivityLog::record($report, 'document_deleted', $penghapus,
            $document->only(['document_type', 'path', 'uploaded_by', 'uploaded_at']), null,
            'Dokumen dihapus petugas sebelum laporan disahkan.');

        Storage::disk('local')->delete($document->path);
        $document->delete();
    }

    /**
     * Semua unggahan Petugas Lapangan nempel ke uuid laporan, bukan ke culprit /
     * involved_vehicles / damaged_items. Dua alasan yang masing-masing berdiri sendiri:
     * id baris anak lahir di server (HP tidak punya apa-apa untuk ditulis saat luring),
     * dan id itu berubah tiap kirim ulang — polymorphic tidak punya FK, jadi tidak ada
     * yang protes waktu dokumennya jadi yatim.
     *
     * `culprit_index` (opsional) memberi tahu pihak mana yang punya foto ini, tanpa
     * FK: cuma posisi di array culprits[] yang sama, bukan culprit.id.
     */
    public function storeForIncident(IncidentReport $report, UploadedFile $file, string $fileUuid, string $documentType, User $uploader, ?int $culpritIndex = null): Document
    {
        $reportUuid = $report->uuid;

        // SETELAH LAPORAN DISAHKAN, cuma surat keringanan yang boleh menyusul.
        //
        // Yang lain sudah jadi lampiran Berita Acara yang diteken, dan lampiran
        // dokumen resmi tidak boleh bertambah setelah tanda tangannya turun.
        // `relief_letter` dikecualikan karena memang lahir belakangan: suratnya
        // bermaterai dan disahkan kelurahan, dan dashboard MCS menghitung "Dokumen
        // Tertunda" dari ketiadaannya — menutupnya berarti hitungan itu tidak akan
        // pernah turun.
        if (! $report->isEditable() && $documentType !== 'relief_letter') {
            throw new DomainException(
                'Laporan sudah disahkan — yang masih bisa diunggah cuma surat permohonan keringanan.',
                'document_type',
            );
        }

        // Path deterministik: tanpa now(), tanpa random, dan tidak diturunkan dari
        // kolom yang bisa diedit (date_time bisa dibetulkan petugas lalu path pindah
        // bulan, upsert meleset, foto dobel). Cuma uuid yang memenuhi syarat itu.
        $berkas = $this->simpan($file, "insiden/{$reportUuid}", $fileUuid);

        // createOrFirst, bukan firstOrCreate: dua unggahan bersamaan dengan file_uuid
        // sama akan menabrak unique index documents.path, dan createOrFirst menangani
        // pelanggaran itu jadi "ambil yang sudah ada" alih-alih 500.
        return Document::createOrFirst(
            ['path' => $berkas['path']],
            [
                'documentable_type' => 'incident_report',
                'documentable_id' => $reportUuid,
                'document_type' => $documentType,
                // Bukan FK — cuma posisi pihak di culprits[] biar FE bisa mencocokkan
                // KTP/SIM/foto kendaraan ke pihak yang benar. Lihat migrasinya.
                'culprit_index' => $culpritIndex,
                // Dari berkas YANG DISIMPAN, lihat simpan(). Bukan getClientMimeType():
                // itu cuma header multipart kiriman HP, tidak divalidasi apa pun, dan
                // dikirim balik apa adanya sebagai Content-Type waktu berkas diunduh —
                // PNG yang dideklarasikan 'text/html' akan dirender browser sebagai HTML.
                'mime_type' => $berkas['mime_type'],
                'size_bytes' => $berkas['size_bytes'],
                'uploaded_by' => $uploader->id,
                'uploaded_at' => now(),
            ],
        );
    }

    /**
     * Foto bukti kerusakan temuan patroli — nempel ke uuid laporan, mirip
     * storeForIncident(). Satu jenis dokumen saja, `patrol_photo`: beda dari insiden,
     * laporan patroli tidak punya KTP/SIM/tanda tangan pihak ketiga untuk dibedakan,
     * jadi tidak ada `document_type` yang perlu diterima dari klien (pola sama
     * dengan `wo_photo` di storeForWorkOrderLog()).
     *
     * @throws DomainException laporan sudah punya Work Order (isEditable() false)
     */
    public function storeForPatrol(PatrolReport $report, UploadedFile $file, string $fileUuid, User $uploader): Document
    {
        if (! $report->isEditable()) {
            throw new DomainException(
                'Laporan sudah ditindaklanjuti Unit Maintenance, tidak bisa diunggah foto baru.',
                'patrol_report',
            );
        }

        $reportUuid = $report->uuid;
        $berkas = $this->simpan($file, "patroli/{$reportUuid}", $fileUuid);

        return Document::createOrFirst(
            ['path' => $berkas['path']],
            [
                'documentable_type' => 'patrol_report',
                'documentable_id' => $reportUuid,
                'document_type' => 'patrol_photo',
                'mime_type' => $berkas['mime_type'],
                'size_bytes' => $berkas['size_bytes'],
                'uploaded_by' => $uploader->id,
                'uploaded_at' => now(),
            ],
        );
    }

    /**
     * Foto bukti Fase 5, menempel ke SATU BARIS LOG.
     *
     * Bukan ke Work Order-nya, dan itu yang membuat "dua foto During pada jam berbeda"
     * bisa diungkapkan sama sekali. Digantung ke work_orders, keduanya kehilangan urutan
     * dan konteksnya, dan `bda_stage` terpaksa disalin ke dokumen — dua tempat untuk
     * satu fakta, yang bisa saling bertentangan tanpa ada yang menengahi.
     *
     * `documentable_id` menyimpan uuid baris log, bukan id bigint. Kolomnya varchar(36)
     * justru untuk ini: polymorphic di sistem ini menampung PK bigint maupun uuid.
     *
     * Satu jenis dokumen saja, `wo_photo`. Foto itu apa sudah diceritakan baris induknya
     * (`entry_type` + `bda_stage`); jenis dokumen yang mengulanginya cuma menambah satu
     * cara untuk bertentangan dengan induknya.
     */
    public function storeForWorkOrderLog(WorkOrderLog $log, UploadedFile $file, string $fileUuid, User $uploader): Document
    {
        // Path dikelompokkan per WORK ORDER, bukan per baris log: satu pekerjaan satu
        // folder, jadi seluruh buktinya bisa dibuka sekaligus waktu evaluasi Fase 6 atau
        // waktu ada sengketa. Konvensi ini sudah dicadangkan di KONVENSI §3.
        $berkas = $this->simpan($file, "wo/{$log->work_order_id}", $fileUuid);

        // createOrFirst, bukan firstOrCreate: dua unggahan bersamaan dengan file_uuid
        // yang sama menabrak unique index documents.path, dan createOrFirst mengubah
        // pelanggaran itu jadi "ambil yang sudah ada" alih-alih 500. Di klien luring
        // yang mengantre unggahan, kiriman ganda itu normal, bukan kelainan.
        return Document::createOrFirst(
            ['path' => $berkas['path']],
            [
                'documentable_type' => 'work_order_logs',
                'documentable_id' => $log->uuid,
                'document_type' => 'wo_photo',
                'mime_type' => $berkas['mime_type'],
                'size_bytes' => $berkas['size_bytes'],
                'uploaded_by' => $uploader->id,
                'uploaded_at' => now(),
            ],
        );
    }

    /**
     * Foto standar jenis aset — SATU slot, bukan galeri. Unggahan baru mengganti yang
     * lama: `standard_photo_id` dipindah ke dokumen baru, lalu baris dan berkas lama
     * dihapus. Beda dari storeForIncident() dkk yang createOrFirst per file_uuid —
     * di sini tidak ada antrean luring untuk dibuat idempoten, cuma "foto sekarang".
     */
    public function storeForAssetType(AssetType $assetType, UploadedFile $file, User $uploader): Document
    {
        $berkas = $this->simpan($file, "asset-types/{$assetType->id}", (string) Str::uuid());

        $document = Document::create([
            'documentable_type' => 'asset_types',
            'documentable_id' => $assetType->id,
            'document_type' => 'standard_photo',
            'mime_type' => $berkas['mime_type'],
            'size_bytes' => $berkas['size_bytes'],
            'uploaded_by' => $uploader->id,
            'uploaded_at' => now(),
        ]);

        $lama = $assetType->standard_photo_id;
        $assetType->update(['standard_photo_id' => $document->id]);
        $this->hapusFotoLama($lama);

        return $document;
    }

    /**
     * Foto standar komponen katalog — pasangan storeForAssetType(), slot sendiri di
     * `asset_catalog.standard_photo_id`. Dua foto ini sengaja terpisah: satu
     * menggambarkan jenis asetnya secara umum, satu menggambarkan komponen spesifik
     * di katalog.
     */
    public function storeForAssetCatalog(AssetCatalog $assetCatalog, UploadedFile $file, User $uploader): Document
    {
        $berkas = $this->simpan($file, "asset-catalog/{$assetCatalog->id}", (string) Str::uuid());

        $document = Document::create([
            'documentable_type' => 'asset_catalog',
            'documentable_id' => $assetCatalog->id,
            'document_type' => 'standard_photo',
            'mime_type' => $berkas['mime_type'],
            'size_bytes' => $berkas['size_bytes'],
            'uploaded_by' => $uploader->id,
            'uploaded_at' => now(),
        ]);

        $lama = $assetCatalog->standard_photo_id;
        $assetCatalog->update(['standard_photo_id' => $document->id]);
        $this->hapusFotoLama($lama);

        return $document;
    }

    private function hapusFotoLama(?int $documentId): void
    {
        if ($documentId === null) {
            return;
        }

        $lama = Document::find($documentId);

        if ($lama !== null) {
            Storage::disk('local')->delete($lama->path);
            $lama->delete();
        }
    }

    /**
     * Satu-satunya jalan berkas unggahan ke disk — semua penyimpan di atas lewat sini,
     * jadi tidak ada jalur unggah yang lolos tanpa kompresi.
     *
     * Foto (JPEG/PNG) dikompres ImageCompressor dan SELALU jadi `.jpg`; path tetap
     * deterministik dari file_uuid, jadi kiriman ulang antrean luring tetap jatuh ke
     * path — dan baris — yang sama. Yang lain (PDF surat keringanan) disimpan apa
     * adanya, ekstensinya ditebak dari ISI berkas, bukan dari nama kiriman HP.
     *
     * mime_type dan size_bytes diambil dari berkas yang DISIMPAN, bukan dari kiriman:
     * GET /documents/{id} mengirim Content-Type dari kolom itu.
     *
     * @return array{path: string, mime_type: string, size_bytes: int}
     *
     * @throws DomainException foto melebihi ImageCompressor::MAX_PIXELS
     */
    private function simpan(UploadedFile $file, string $folder, string $fileUuid): array
    {
        $jpeg = ImageCompressor::compress($file->getRealPath());

        if ($jpeg === null) {
            $path = "{$folder}/{$fileUuid}.".$file->extension();
            Storage::disk('local')->putFileAs($folder, $file, basename($path));

            return ['path' => $path, 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize()];
        }

        try {
            $path = "{$folder}/{$fileUuid}.jpg";
            Storage::disk('local')->putFileAs($folder, new File($jpeg), basename($path));

            return ['path' => $path, 'mime_type' => 'image/jpeg', 'size_bytes' => filesize($jpeg)];
        } finally {
            @unlink($jpeg);
        }
    }
}
