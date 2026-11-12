<?php

namespace App\Services\Mcs;

use App\Exceptions\DomainException;
use App\Models\ActivityLog;
use App\Models\DamageBa;
use App\Models\Document;
use App\Models\IncidentBa;
use App\Models\IncidentReport;
use App\Models\User;
use App\Notifications\IncidentReportNotification;
use App\Services\Shared\DocumentStorageService;
use App\Services\Shared\IncidentReportSyncService;
use App\Services\Shared\NumberGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2 — apa yang boleh dilakukan MCS Supervisor terhadap satu laporan.
 *
 * DUA BERITA ACARA, DUA PENGESAHAN TERPISAH. Bukan satu aksi yang menerbitkan
 * keduanya: BA Kejadian menyangkut kronologi dan pihak terlibat, BA Kerusakan
 * menyangkut kalkulasi volume dan nilai. Yang satu bisa benar sementara yang lain
 * salah, dan alasan penolakannya memang berbeda. Skemanya sudah disiapkan begitu —
 * validation_status, rejection_reason, validated_by, dan validated_at ada DUA set,
 * satu di tiap tabel; digabung jadi satu aksi, satu set selamanya kosong.
 *
 * URUTANNYA MENGIKAT: BA Kejadian dulu, baru BA Kerusakan. Bukan aturan yang dikarang
 * di sini — damage_ba.damage_report_id itu FK NOT NULL ke incident_ba.uuid, jadi BA
 * Kerusakan memang tidak punya tempat berdiri sebelum BA Kejadian ada.
 */
class IncidentVerificationService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly IncidentReportSyncService $sync,
        private readonly BaPdfService $pdf,
        private readonly DocumentStorageService $documents,
    ) {}

    /**
     * Sahkan BA Kejadian. Di sinilah `report_number` terbit — titik "laporan diakui".
     */
    public function validateIncidentBa(IncidentReport $report, User $mcs, array $data): IncidentBa
    {
        return DB::transaction(function () use ($report, $mcs, $data) {
            $report = $this->lock($report);
            $this->assertQueued($report);

            $ba = $report->incidentBa()->first();
            $this->assertNotLocked($ba, 'BA Kejadian');

            $incidentBa = IncidentBa::updateOrCreate(
                ['incident_report_id' => $report->uuid],
                [
                    // Nomor dipertahankan kalau barisnya sudah ada: BA yang pernah
                    // ditolak lalu diperbaiki dan disahkan tetap memakai nomor yang
                    // sama. Menerbitkan nomor kedua berarti satu perkara punya dua
                    // nomor BA di arsip, dan yang pertama menggantung tanpa dokumen.
                    'incident_ba_number' => $ba?->incident_ba_number ?? $this->numbers->next('incident_ba'),
                    // Redesain 16 Sep: penanda tangan sudah ditentukan petugas saat
                    // submit, bukan diketik MCS di sini lagi. Disalin, bukan diminta.
                    'officer_1_id' => $report->user_id,
                    'officer_2_id' => $report->officer_2_id,
                    'duty_period' => $data['duty_period'] ?? null,
                    'shift' => $report->shift(),
                    'validation_status' => 'validated',
                    'validated_by' => $mcs->id,
                    'validated_at' => now(),
                    // Dikosongkan: alasan penolakan putaran sebelumnya tidak boleh
                    // ikut tercetak di BA yang akhirnya disahkan.
                    'rejection_reason' => null,
                ],
            );

            $report->update([
                'report_number' => $report->report_number ?? $this->numbers->next('report'),
                // Laporan tanpa kerusakan aset tuntas di sini — tidak ada BA Kerusakan
                // yang ditunggu, dan tidak ada Work Order yang akan menutupnya
                // belakangan (WO hanya terbit dari damage_ba). Kalau tetap diparkir di
                // 'verified', laporan ini tidak akan pernah menyentuh 'closed' dan
                // tersandera selamanya di tab yang salah (diskusi tim 17 Sep).
                'report_status' => $report->is_asset_broken ? 'pending_verification' : 'closed',
            ]);

            $this->beriTahuPetugas($report, IncidentReportNotification::INCIDENT_BA_VALIDATED,
                'BA Kejadian '.IncidentReportNotification::label($report).' disahkan.'
                    .($report->is_asset_broken ? '' : ' Tidak ada kerusakan aset, laporan selesai.'));

            $this->pdf->freeze($incidentBa);

            return $incidentBa;
        });
    }

    /**
     * Tolak BA Kejadian — kronologi atau pihak terlibat yang harus diperbaiki petugas.
     */
    public function rejectIncidentBa(IncidentReport $report, User $mcs, array $data): IncidentBa
    {
        return DB::transaction(function () use ($report, $mcs, $data) {
            $report = $this->lock($report);
            $this->assertQueued($report);

            $ba = $report->incidentBa()->first();
            $this->assertNotLocked($ba, 'BA Kejadian');

            $incidentBa = IncidentBa::updateOrCreate(
                ['incident_report_id' => $report->uuid],
                [
                    // Nomor tetap terbit walau ditolak — kolomnya NOT NULL, dan
                    // barisnya harus ada untuk menampung rejection_reason. Yang TIDAK
                    // terbit itu report_number: nomor laporan penanda perkara yang
                    // sudah diakui (keputusan 31 Ags), dan yang ditolak belum.
                    'incident_ba_number' => $ba?->incident_ba_number ?? $this->numbers->next('incident_ba'),
                    'officer_1_id' => $report->user_id,
                    'officer_2_id' => $report->officer_2_id,
                    'duty_period' => $data['duty_period'] ?? null,
                    'shift' => $report->shift(),
                    'validation_status' => 'rejected',
                    'validated_by' => $mcs->id,
                    'validated_at' => now(),
                    'rejection_reason' => $data['rejection_reason'],
                ],
            );

            $report->update(['report_status' => 'rejected']);

            $this->beriTahuPetugas($report, IncidentReportNotification::INCIDENT_BA_REJECTED,
                'BA Kejadian '.IncidentReportNotification::label($report).' ditolak: '.rtrim($data['rejection_reason'], '. ').'. Perbaiki lalu kirim ulang.');

            return $incidentBa;
        });
    }

    /**
     * Sahkan BA Kerusakan. Tidak menerima apa pun dari klien — seluruh isinya
     * diturunkan, termasuk nilai ganti ruginya.
     */
    public function validateDamageBa(IncidentReport $report, User $mcs): DamageBa
    {
        return DB::transaction(function () use ($report, $mcs) {
            $report = $this->lock($report);
            $this->assertQueued($report);
            $incidentBa = $this->assertIncidentBaValidated($report);

            $ba = $incidentBa->damageBa()->first();
            $this->assertNotLocked($ba, 'BA Kerusakan');

            $damageBa = DamageBa::updateOrCreate(
                ['damage_report_id' => $incidentBa->uuid],
                [
                    'damage_ba_number' => $ba?->damage_ba_number ?? $this->numbers->next('damage_ba'),
                    'supervisor_id' => $mcs->id,
                    'shift' => $incidentBa->shift,
                    'total_amount' => $report->total_estimation,
                    'amount_in_words' => $this->pdf->terbilang($report->total_estimation),
                    'validation_status' => 'validated',
                    'validated_by' => $mcs->id,
                    'validated_at' => now(),
                    'rejection_reason' => null,
                ],
            );

            $report->update(['report_status' => 'verified']);

            $this->beriTahuPetugas($report, IncidentReportNotification::DAMAGE_BA_VALIDATED,
                'BA Kerusakan '.IncidentReportNotification::label($report).' disahkan. Kerusakannya masuk antrean perbaikan.');

            $this->pdf->freeze($damageBa);

            return $damageBa;
        });
    }

    /**
     * Tolak BA Kerusakan — kalkulasi volume atau jenis aset yang harus diperbaiki.
     *
     * BA Kejadian yang sudah disahkan TIDAK disentuh: kronologinya memang tidak salah,
     * dan nomornya sudah terbit. Laporan balik ke petugas hanya untuk membetulkan
     * daftar kerusakannya.
     */
    public function rejectDamageBa(IncidentReport $report, User $mcs, array $data): DamageBa
    {
        return DB::transaction(function () use ($report, $mcs, $data) {
            $report = $this->lock($report);
            $this->assertQueued($report);
            $incidentBa = $this->assertIncidentBaValidated($report);

            $ba = $incidentBa->damageBa()->first();
            $this->assertNotLocked($ba, 'BA Kerusakan');

            $damageBa = DamageBa::updateOrCreate(
                ['damage_report_id' => $incidentBa->uuid],
                [
                    'damage_ba_number' => $ba?->damage_ba_number ?? $this->numbers->next('damage_ba'),
                    'supervisor_id' => $mcs->id,
                    'shift' => $incidentBa->shift,
                    'total_amount' => $report->total_estimation,
                    'amount_in_words' => $this->pdf->terbilang($report->total_estimation),
                    'validation_status' => 'rejected',
                    'validated_by' => $mcs->id,
                    'validated_at' => now(),
                    'rejection_reason' => $data['rejection_reason'],
                ],
            );

            $report->update(['report_status' => 'rejected']);

            $this->beriTahuPetugas($report, IncidentReportNotification::DAMAGE_BA_REJECTED,
                'BA Kerusakan '.IncidentReportNotification::label($report).' ditolak: '.rtrim($data['rejection_reason'], '. ').'. Perbaiki daftar kerusakannya lalu kirim ulang.');

            return $damageBa;
        });
    }

    /**
     * Koreksi MCS sebelum pengesahan — salah ketik, bukan penulisan ulang laporan.
     */
    public function correct(IncidentReport $report, User $mcs, array $data): IncidentReport
    {
        return DB::transaction(function () use ($report, $mcs, $data) {
            $report = $this->lock($report);
            $this->assertQueued($report, ['pending_verification']);

            $old = [];
            $new = [];

            $lokasiTerkunci = $report->incidentBaValidated();

            foreach (['km', 'm', 'route', 'lane', 'location_type'] as $field) {
                if (! array_key_exists($field, $data) || (string) $report->$field === (string) $data[$field]) {
                    continue;
                }

                if ($lokasiTerkunci) {
                    throw new DomainException(
                        'Lokasi sudah tercetak di BA Kejadian yang disahkan dan tidak bisa dikoreksi lagi. '
                        .'Yang masih bisa dikoreksi cuma volume kerusakan.',
                        $field,
                    );
                }

                $old[$field] = $report->$field;
                $new[$field] = $data[$field];
                $report->$field = $data[$field];
            }

            foreach ($this->reprice($report, $data['items'] ?? []) as $field => [$before, $after]) {
                $old[$field] = $before;
                $new[$field] = $after;
            }

            if ($new === []) {
                throw new DomainException('Tidak ada perubahan yang dikirim.', 'items');
            }

            $report->total_estimation = $report->damagedItems()->sum('subtotal') + $report->additional_cost;
            $report->save();

            ActivityLog::record($report, 'correction', $mcs, $old, $new,
                'Koreksi data laporan oleh MCS Supervisor sebelum pengesahan.');

            return $report;
        });
    }

    /**
     * MCS melampirkan surat permohonan keringanan yang dibawa pengguna jalan ke kantor.
     *
     * Surat cuma MENEMPEL: status laporan, total BA, dan pledged_amount tidak berubah.
     * Satu-satunya akibatnya laporan keluar dari antrean masa tenggang. Keputusan
     * keringanannya sendiri ada di Manager Dalop / GM / Direktur (SK Pasal 3), di luar
     * aplikasi.
     *
     * Penyimpanannya meminjam jalur unggahan petugas apa adanya — sudah mengizinkan
     * `relief_letter` setelah laporan disahkan, dan sudah idempoten lewat file_uuid.
     */
    public function uploadReliefLetter(IncidentReport $report, UploadedFile $file, string $fileUuid, User $mcs): Document
    {
        if (! $report->relief_requested) {
            throw new DomainException(
                'Laporan ini tidak mengajukan keringanan, jadi tidak ada surat keringanan yang bisa dilampirkan.',
                'relief_requested',
            );
        }

        return $this->documents->storeForIncident($report, $file, $fileUuid, 'relief_letter', $mcs);
    }

    /**
     * Ke pembuat laporan saja — dia yang bisa membetulkan dan mengirim ulang
     * (IncidentReportPolicy::update), bukan petugas kedua yang cuma ikut menandatangani.
     */
    private function beriTahuPetugas(IncidentReport $report, string $event, string $pesan): void
    {
        User::find($report->user_id)?->notify(new IncidentReportNotification($report, $event, $pesan));
    }

    /**
     * Susun link wa.me untuk meneruskan permohonan keringanan.
     *
     * TANPA nomor tujuan: WhatsApp membuka layar pilih kontak dan MCS yang memilih.
     * Keputusan keringanan berjenjang menurut nilai kerugian (SK Pasal 3 ayat 3 —
     * Manager Dalop / GM / Direktur) dan tujuannya belum pasti (Mentok #5), jadi satu
     * nomor tetap di .env justru mengunci ke tujuan yang bisa salah. Sistem berhenti di
     * "sudah disahkan, silakan diteruskan" — tidak ada tombol setujui/tolak keringanan.
     *
     * Data pribadi pelaku (nama, NIK, SIM, HP) sengaja tidak ikut: begitu masuk
     * WhatsApp, data itu keluar dari kendali sistem dan tidak bisa ditarik lagi.
     */
    public function escalationLink(IncidentReport $report, User $mcs): string
    {
        // 'closed' ikut diterima: laporan yang kerusakannya sudah selesai diperbaiki
        // (WO tuntas) tetap boleh minta keringanan atas nilai yang sudah disahkan —
        // penutupan fisik tidak menutup hak mengajukan keringanan.
        if (! $report->relief_requested || ! in_array($report->report_status, ['verified', 'closed'], true)) {
            throw new DomainException(
                'Eskalasi hanya untuk laporan yang mengajukan keringanan dan sudah disahkan.',
                'relief_requested',
            );
        }

        $damageBa = $report->incidentBa?->damageBa;

        if ($damageBa?->validation_status !== 'validated') {
            throw new DomainException(
                'Belum ada BA Kerusakan yang disahkan, jadi belum ada nilai ganti rugi yang bisa dimintakan keringanan.',
                'damage_ba',
            );
        }

        $rp = fn ($nilai) => 'Rp '.number_format((float) $nilai, 0, ',', '.');
        $total = (float) $report->total_estimation;

        // Tanpa nominal disanggupi, persen dan selisih cuma angka karangan — barisnya dibuang.
        $nilai = $report->pledged_amount === null
            ? ['*Disanggupi:* -']
            : [
                '*Disanggupi:* '.$rp($report->pledged_amount).' ('.($total > 0 ? round($report->pledged_amount / $total * 100) : 0).'%)',
                '*Selisih:* '.$rp($total - $report->pledged_amount),
            ];

        // Surat yang sudah masuk tidak butuh tenggat lagi.
        $tenggat = $damageBa->relief_deadline;
        $surat = $report->documents()->where('document_type', 'relief_letter')->exists()
            ? ['*Surat permohonan:* sudah diterima']
            : [
                '*Surat permohonan:* belum diterima'.($tenggat?->isPast() ? ' (*lewat tenggat*)' : ''),
                '*Tenggat surat:* '.($tenggat?->locale('id')->isoFormat('D MMMM YYYY') ?? '-'),
            ];

        $text = implode("\n", [
            '*ESKALASI PERMOHONAN KERINGANAN*',
            'Ruas Tol Jogja–Solo',
            '',
            '*BA Kerusakan:* '.$damageBa->damage_ba_number,
            '*No. Laporan:* '.($report->report_number ?? '-'),
            '*Kejadian:* '.($report->date_time?->copy()->locale('id')->isoFormat('dddd, D MMMM YYYY, HH.mm') ?? '-'),
            sprintf('*Lokasi:* KM %s+%s, Jalur %s, %s', $report->km ?? '-', $report->m ?? '-', $report->route ?? '-', $report->lane ?? '-'),
            '',
            '*Nilai kerugian:* '.$rp($total),
            ...$nilai,
            '',
            ...$surat,
            '',
            'Mohon arahan atas permohonan keringanan ini.',
            '',
            "_Diteruskan oleh {$mcs->name} (MCS) via Toll Command_",
        ]);

        ActivityLog::record($report, 'escalation_forwarded', $mcs, null, null,
            'Permohonan keringanan diteruskan lewat WhatsApp.');

        return 'https://wa.me/?text='.rawurlencode($text);
    }

    private function reprice(IncidentReport $report, array $edits): array
    {
        if ($edits === []) {
            return [];
        }

        $rows = $report->damagedItems()->whereIn('id', array_column($edits, 'id'))->get()->keyBy('id');

        if ($rows->count() !== count($edits)) {
            throw new DomainException('Ada baris kerusakan yang bukan milik laporan ini.', 'items');
        }

        $priced = $this->sync->price(array_map(fn (array $edit) => [
            'asset_catalog_id' => $rows[$edit['id']]->asset_catalog_id,
            'catalog_price_id' => $rows[$edit['id']]->catalog_price_id,
            'quantity' => $edit['quantity'] ?? $rows[$edit['id']]->quantity,
            'damage_percentage' => $edit['damage_percentage'] ?? $rows[$edit['id']]->damage_percentage,
        ], $edits));

        $changes = [];

        foreach ($edits as $i => $edit) {
            $row = $rows[$edit['id']];
            $after = $priced[$i];

            foreach (['quantity', 'damage_percentage', 'subtotal'] as $field) {
                if ((float) $row->$field !== (float) $after[$field]) {
                    $changes["items.{$row->id}.{$field}"] = [$row->$field, $after[$field]];
                }
            }

            $row->update([
                'quantity' => $after['quantity'],
                'damage_percentage' => $after['damage_percentage'],
                'subtotal' => $after['subtotal'],
            ]);
        }

        return $changes;
    }

    private function lock(IncidentReport $report): IncidentReport
    {
        return IncidentReport::lockForUpdate()->findOrFail($report->uuid);
    }

    private const STATUS_AKSI_BA = ['pending_verification', 'rejected'];

    private function assertQueued(IncidentReport $report, array $status = self::STATUS_AKSI_BA): void
    {
        if (! in_array($report->report_status, $status, true)) {
            throw new DomainException(
                'Laporan ini tidak sedang menunggu verifikasi.',
                'report_status',
            );
        }
    }

    private function assertNotLocked(IncidentBa|DamageBa|null $ba, string $label): void
    {
        if ($ba?->validation_status === 'validated') {
            throw new DomainException("{$label} sudah disahkan dan terkunci.", 'validation_status');
        }
    }

    private function assertIncidentBaValidated(IncidentReport $report): IncidentBa
    {
        if (! $report->is_asset_broken) {
            throw new DomainException(
                'Laporan ini tidak mencatat kerusakan aset, jadi tidak ada BA Kerusakan yang terbit.',
                'is_asset_broken',
            );
        }

        $incidentBa = $report->incidentBa()->first();

        if ($incidentBa?->validation_status !== 'validated') {
            throw new DomainException(
                'BA Kejadian harus disahkan lebih dulu sebelum BA Kerusakan.',
                'incident_ba',
            );
        }

        return $incidentBa;
    }
}
