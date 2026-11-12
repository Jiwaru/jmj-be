<?php

namespace App\Services\Mcs;

use App\Exceptions\DomainException;
use App\Models\DamageBa;
use App\Models\IncidentBa;
use App\Models\IncidentReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use NumberFormatter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mencetak dua Berita Acara sesuai formulir SK 40-KPTS-JMJ-XI-2025 halaman 18-19.
 *
 * SATU ENDPOINT, DUA PERILAKU:
 *
 *   pdf_path terisi  -> stream berkas beku dari disk
 *   pdf_path kosong  -> render dari data sekarang, bertanda DRAFT, tidak disimpan
 *
 * Itu yang memberi layar MCS pratinjau "BA_Kejadian.pdf - DRAFT" sebelum pengesahan
 * tanpa endpoint kedua. Dan setelah disahkan, yang keluar bytes yang SAMA selamanya:
 * BA yang sudah diteken harus menunjukkan angka yang sama lima tahun kemudian,
 * walaupun katalog harga sudah direvisi dua kali sejak itu.
 *
 * Berkasnya masuk disk 'local' (storage/app/private), bukan 'public' — isinya NIK,
 * nomor SIM, alamat, dan pekerjaan pihak ketiga dalam satu halaman. TIDAK ada baris
 * `documents`: keputusan 31 Ags membuang document_type 'minutes_pdf' justru supaya
 * tidak ada dua rumah untuk satu berkas.
 *
 * Tanpa queue, dan itu bukan kelalaian: satu halaman DomPDF ratusan milidetik dan
 * dirender sekali seumur BA. Menambah queue driver untuk itu tidak dibayar apa pun.
 */
class BaPdfService
{
    private const ROMAN_SHIFT = ['1' => 'I', '2' => 'II', '3' => 'III'];

    /**
     * Bekukan PDF ke disk dan catat path-nya. Dipanggil saat BA disahkan.
     */
    public function freeze(IncidentBa|DamageBa $ba): void
    {
        $path = $ba instanceof IncidentBa
            ? "ba/incident/{$ba->uuid}.pdf"
            : "ba/damage/{$ba->uuid}.pdf";

        Storage::disk('local')->put($path, $this->render($ba, draft: false));

        $ba->forceFill(['pdf_path' => $path])->save();
    }

    /**
     * Sajikan PDF-nya: yang beku dari disk, yang belum disahkan dirender saat itu juga.
     */
    public function download(IncidentBa|DamageBa $ba): Response|StreamedResponse
    {
        $name = $ba instanceof IncidentBa ? 'BA_Kejadian.pdf' : 'BA_Kerusakan.pdf';

        // inline, bukan attachment: tombol di layar MCS itu Preview dan Print.
        $headers = [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$name}\"",
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (filled($ba->pdf_path) && Storage::disk('local')->exists($ba->pdf_path)) {
            return Storage::disk('local')->response($ba->pdf_path, $name, $headers);
        }

        return response($this->render($ba, draft: true), 200, $headers);
    }

    /**
     * PDF BA dari uuid LAPORAN — yang membuat pratinjau sebelum ACC MCS mungkin.
     *
     * Endpoint berbasis uuid BA tidak bisa: baris BA baru lahir saat MCS Sahkan/Tolak,
     * jadi laporan yang belum disentuh tidak punya uuid untuk dipanggil. Yang sah →
     * berkas beku apa adanya. Selain itu → DRAFT dari data laporan SEKARANG, termasuk
     * BA yang ditolak: baris itu menyimpan total saat ditolak, dan setelah petugas
     * membetulkan volume, angkanya sudah basi.
     */
    public function preview(IncidentReport $report, string $jenis): Response|StreamedResponse
    {
        $ba = $jenis === 'incident' ? $report->incidentBa : $report->incidentBa?->damageBa;

        if ($ba?->validation_status === 'validated') {
            return $this->download($ba);
        }

        return $this->download($jenis === 'incident' ? $this->draftIncidentBa($report) : $this->draftDamageBa($report));
    }

    /**
     * BA Kejadian yang TIDAK disimpan — isinya meniru validateIncidentBa(). Nomornya
     * kosong sampai ACC (kecuali BA yang pernah ditolak, nomornya sudah terbit).
     */
    public function draftIncidentBa(IncidentReport $report): IncidentBa
    {
        $lama = $report->incidentBa;

        return (new IncidentBa)->forceFill([
            'incident_ba_number' => $lama?->incident_ba_number,
            'incident_report_id' => $report->uuid,
            'officer_1_id' => $report->user_id,
            'officer_2_id' => $report->officer_2_id,
            'duty_period' => $lama?->duty_period,
            'shift' => $report->shift(),
        ]);
    }

    /**
     * BA Kerusakan yang TIDAK disimpan — isinya meniru validateDamageBa(). Boleh
     * sebelum BA Kejadian ada: ini cuma pratinjau, urutan pengesahannya tetap dijaga
     * IncidentVerificationService. Slot MCS kosong: siapa yang meneken baru ketahuan saat ACC.
     */
    public function draftDamageBa(IncidentReport $report): DamageBa
    {
        if (! $report->is_asset_broken) {
            throw new DomainException('Laporan tanpa kerusakan aset tidak punya BA Kerusakan.', 'damage_ba');
        }

        $bak = $report->incidentBa ?? $this->draftIncidentBa($report);

        return (new DamageBa)->forceFill([
            'damage_ba_number' => $bak->damageBa?->damage_ba_number,
            'shift' => $bak->shift,
            'total_amount' => $report->total_estimation,
            'amount_in_words' => $this->terbilang($report->total_estimation),
        ])->setRelation('incidentBa', $bak);
    }

    public function terbilang(float|string $amount): string
    {
        return (new NumberFormatter('id_ID', NumberFormatter::SPELLOUT))
            ->format((int) round((float) $amount)).' rupiah';
    }

    /**
     * HTML-nya sebelum jadi PDF.
     *
     * Publik supaya isi Berita Acara bisa diuji sebagai teks — di dalam PDF, stream-nya
     * terkompresi dan yang terbaca cuma byte. Yang diuji di sini memang isinya (nomor
     * ber-format SK, persentase kerusakan, slot Seksi yang kosong); mengubah HTML jadi
     * PDF itu urusan DomPDF, bukan urusan kita.
     */
    public function html(IncidentBa|DamageBa $ba, bool $draft = false): string
    {
        [$view, $data] = $ba instanceof IncidentBa
            ? ['pdf.incident-ba', $this->incidentData($ba)]
            : ['pdf.damage-ba', $this->damageData($ba)];

        return view($view, $data + [
            'draft' => $draft,
            'deputy' => config('toll.deputy_am_tm'),
        ])->render();
    }

    private function render(IncidentBa|DamageBa $ba, bool $draft): string
    {
        [$view, $data] = $ba instanceof IncidentBa
            ? ['pdf.incident-ba', $this->incidentData($ba)]
            : ['pdf.damage-ba', $this->damageData($ba)];

        return Pdf::loadView($view, $data + [
            'draft' => $draft,
            'deputy' => config('toll.deputy_am_tm'),
        ])
            ->setPaper('a4')
            // Tanpa ini DomPDF menanam font DejaVu UTUH di tiap berkas: satu halaman
            // hitam-putih jadi 880 KB, dan tiap BA menambah segitu di NAS selamanya.
            // Subsetting cuma menanam glyph yang benar-benar dipakai.
            ->setOption('isFontSubsettingEnabled', true)
            ->output();
    }

    private function incidentData(IncidentBa $ba): array
    {
        // fresh(), BUKAN loadMissing(): loadMissing memuat relasi ke objek YANG SAMA
        // yang dipegang pemanggil, dan objek itu yang dikembalikan controller sebagai
        // response. Akibatnya bentuk response API ikut apa pun yang template PDF
        // kebetulan butuh — tambah satu relasi di sini, response endpoint pengesahan
        // membengkak tanpa ada yang sadar. Salinan terpisah memutus sambungan itu.
        // Draft (belum tersimpan) tidak punya baris untuk di-fresh — relasinya
        // lazy-load lewat FK yang sudah diisi draftIncidentBa().
        $ba = $ba->exists ? $ba->fresh(['incidentReport', 'officer1', 'officer2', 'validator']) : $ba;
        $report = $ba->incidentReport;

        return [
            'ba' => $ba,
            'report' => $report,
            'officers' => [$ba->officer1, $ba->officer2],
            'mcs' => $ba->validator,
            'shift' => self::ROMAN_SHIFT[$ba->shift] ?? $ba->shift,
            // "Pada hari ini ..." = hari BA-nya dibuat, bukan hari kejadiannya.
            // Yang belum disahkan memakai hari ini supaya pratinjaunya masuk akal.
            'dibuat' => $this->tanggal($ba->validated_at ?? Carbon::now()),
            'kejadian' => $this->tanggal($report->date_time),
            // Slot "Kejadian tersebut berupa ......". Diisi `causer` (keputusan Rafiq
            // 1 Sep) — bacanya sedikit melenceng, karena causer itu PENYEBAB sementara
            // formulir meminta JENIS kejadian. Tidak ada kolom yang lebih dekat, dan
            // mengarang enum jenis kejadian berarti menebak daftar yang kantor belum
            // sebutkan. 'Lainnya' dicetak teks bebasnya, bukan kata "Lainnya".
            'berupa' => $report->causer === 'Lainnya' ? $report->causer_other : $report->causer,
        ];
    }

    private function damageData(DamageBa $ba): array
    {
        // fresh(), bukan loadMissing() — alasannya sama dengan incidentData().
        $ba = $ba->exists ? $ba->fresh([
            'incidentBa.incidentReport.culprits.involvedVehicle',
            'incidentBa.incidentReport.involvedVehicles',
            'incidentBa.incidentReport.damagedItems.assetCatalog.assetType',
            'incidentBa.incidentReport.damagedItems.catalogPrice',
            'supervisor',
        ]) : $ba;

        $report = $ba->incidentBa->incidentReport;
        // Formulir SK cuma punya SATU blok PIHAK KESATU. Plafon yang sudah tercatat
        // di keputusan 31 Ags: dua pelaku di satu insiden belum bisa dibedakan di
        // dokumen ini. Yang dicetak pelaku pertama.
        $culprit = $report->culprits->first();

        return [
            'ba' => $ba,
            'report' => $report,
            'culprit' => $culprit,
            'vehicle' => $culprit?->involvedVehicle ?? $report->involvedVehicles->first(),
            'items' => $report->damagedItems,
            'mcs' => $ba->supervisor,
            'shift' => self::ROMAN_SHIFT[$ba->shift] ?? $ba->shift,
            'dibuat' => $this->tanggal($ba->validated_at ?? Carbon::now()),
            'kejadian' => $this->tanggal($report->date_time),
            'seksi' => $report->section(),
        ];
    }

    private function tanggal(?Carbon $at): array
    {
        $at = ($at ?? Carbon::now())->locale('id');

        return [
            'hari' => $at->isoFormat('dddd'),
            'tanggal' => $at->isoFormat('D'),
            'bulan' => $at->isoFormat('MMMM'),
            'tahun' => $at->format('Y'),
            'tahun_huruf' => (new NumberFormatter('id_ID', NumberFormatter::SPELLOUT))
                ->format((int) $at->format('Y') - 2000),
            'jam' => $at->format('H'),
            'menit' => $at->format('i'),
        ];
    }
}
