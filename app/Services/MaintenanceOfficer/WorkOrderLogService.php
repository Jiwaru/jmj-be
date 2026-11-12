<?php

namespace App\Services\MaintenanceOfficer;

use App\Exceptions\DomainException;
use App\Models\Document;
use App\Models\WorkOrder;
use App\Models\WorkOrderLog;
use App\Models\User;
use App\Notifications\WorkOrderNotification;
use App\Services\Shared\DocumentStorageService;
use App\Support\Shift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Fase 5 — apa yang boleh ditulis Petugas Maintenance ke garis waktu sebuah pekerjaan.
 *
 * SATU PINTU TULIS untuk enam aksi (terima, verifikasi APD, catat progres, oper shift,
 * lapor kendala, ajukan selesai). Yang membedakannya `entry_type`, dan transisi status
 * yang menyertainya dibaca dari SATU tabel — WorkOrder::LOG_TRANSITIONS. Tersebar jadi
 * lima method, urutan status bisa dilanggar dari satu cabang tanpa cabang lain tahu, dan
 * yang rusak bukan satu request melainkan arti kolom `repair_status` untuk seluruh baris
 * setelahnya.
 *
 * BARIS LOG TIDAK PERNAH DITIMPA. `sync()` idempoten pada uuid yang lahir di HP: kiriman
 * ulang karena sinyal putus mengembalikan baris yang sudah ada apa adanya, bukan menulis
 * ulang isinya. Garis waktu itu kesaksian tentang apa yang terjadi pada jam tertentu —
 * menyuntingnya belakangan berarti menulis ulang sejarah, dan Fase 6 mengevaluasi
 * pekerjaan berdasarkan garis waktu ini.
 */
class WorkOrderLogService
{
    public function __construct(private readonly DocumentStorageService $documents) {}

    /**
     * Tulis satu baris log, lalu jalankan transisi status yang menyertainya.
     *
     * @throws DomainException status pekerjaannya tidak mengizinkan aksi ini
     */
    public function sync(string $uuid, WorkOrder $workOrder, User $petugas, array $data): WorkOrderLog
    {
        return DB::transaction(function () use ($uuid, $workOrder, $petugas, $data) {
            // Dikunci SEBELUM baris lama dicari: dua kiriman bersamaan dengan uuid yang
            // sama harus dijawab satu baris, bukan satu baris plus satu unique violation.
            $workOrder = WorkOrder::lockForUpdate()->findOrFail($workOrder->id);

            // Idempotensi. Dijawab sebelum aturan status diperiksa, dan itu disengaja:
            // kiriman ulang datang SESUDAH transisinya jalan, jadi statusnya sekarang
            // sudah bukan status yang dibolehkan lagi. Diperiksa duluan, retry yang
            // seharusnya berhasil malah dijawab 422 dan HP akan mengulanginya selamanya.
            if ($lama = WorkOrderLog::find($uuid)) {
                return $lama;
            }

            $jenis = $data['entry_type'];
            $waktu = Carbon::parse($data['logged_at']);

            $this->pastikanBoleh($workOrder, $jenis);
            $this->pastikanKoordinator($workOrder, $petugas, $jenis);

            $log = WorkOrderLog::create([
                'uuid' => $uuid,
                'work_order_id' => $workOrder->id,
                'entry_type' => $jenis,
                'bda_stage' => $data['bda_stage'] ?? null,
                'user_id' => $petugas->id,
                // shift dan day_number DITURUNKAN, tidak diminta dari layar. Dua-duanya
                // fungsi dari `logged_at`; memintanya berarti membuka kemungkinan shift
                // melenceng dari jam pada baris yang sama — persis alasan yang sama
                // dengan incident_report (keputusan 2 Sep).
                'shift' => Shift::at($waktu),
                'day_number' => $this->hariKe($workOrder, $waktu),
                'logged_at' => $waktu,
                'notes' => $data['notes'] ?? null,
                'apd_items' => $data['apd_items'] ?? null,
                'reason' => $data['reason'] ?? null,
                'title' => $data['title'] ?? null,
                'handover_to_id' => $data['handover_to_id'] ?? null,
            ]);

            $this->jalankanTransisi($workOrder, $petugas, $jenis, $waktu);
            $this->operKoordinator($workOrder, $petugas, $log);
            $this->beriTahuUnit($workOrder, $petugas, $log);

            return $log;
        });
    }

    /**
     * Foto bukti menempel ke BARIS LOG, bukan ke Work Order-nya.
     *
     * Itu yang membuat "dua foto During pada jam berbeda" bisa diungkapkan sama sekali —
     * digantung ke work_orders, keduanya kehilangan urutan dan konteks, dan `bda_stage`
     * terpaksa disimpan dua kali di tempat yang bisa saling bertentangan.
     */
    public function attachPhoto(WorkOrderLog $log, $file, string $fileUuid, User $petugas)
    {
        return $this->documents->storeForWorkOrderLog($log, $file, $fileUuid, $petugas);
    }

    /**
     * "Terima tugas" dan "Ajukan selesai" cuma oleh KOORDINATOR (keputusan PO 5 Okt).
     * Log lain — APD, progres, kendala, oper shift, foto — tetap boleh semua anggota.
     *
     * 422, BUKAN 403. Dia anggota regu (WorkOrderPolicy::log sudah meloloskannya); yang
     * belum mengizinkan KEADAANNYA, dan keadaan itu bisa berubah: koordinator berganti
     * lewat oper shift, dan di aplikasi luring "ajukan selesai" dari koordinator baru
     * bisa sampai server SEBELUM log oper shift dari koordinator lama. 422 membuat
     * antrean HP mencoba lagi dan berhasil begitu oper shiftnya masuk; 403 dibuang.
     *
     * WO lama sudah diberi koordinator oleh migration 2026_10_05_000001 (dari penerima
     * tugas / anggota tunggal). Yang tersisa tanpa koordinator pasti masih `new`, dan Unit
     * menunjuknya lewat ganti regu sebelum tugas bisa diterima.
     */
    private function pastikanKoordinator(WorkOrder $workOrder, User $petugas, string $jenis): void
    {
        if (! in_array($jenis, ['start', 'complete'], true) || $workOrder->coordinatorId() === $petugas->id) {
            return;
        }

        throw new DomainException(
            $jenis === 'start'
                ? 'Hanya koordinator regu yang bisa menerima tugas ini.'
                : 'Hanya koordinator regu yang bisa mengajukan pekerjaan ini selesai.',
            'coordinator',
        );
    }

    /**
     * Koordinator berganti LEWAT OPER SHIFT — satu-satunya jalan setelah tugas diterima
     * (keputusan PO 5 Okt; Unit tidak bisa menggantinya lewat ganti regu).
     *
     * Cuma oper shift yang ditulis KOORDINATOR SENDIRI dan menyebut penerima. Oper shift
     * anggota biasa tetap tercatat, tapi tidak memindahkan peran — kalau bisa, siapa pun
     * mengambil alih koordinator tanpa sepengetahuannya. Tanpa penerima = koordinator
     * tetap (`handover_to_id` opsional demi aplikasi luring versi lama).
     *
     * Kiriman ulang aman: sync() mengembalikan baris lama SEBELUM sampai ke sini.
     * Urutan update penting — lepas dulu yang lama, baru pasang yang baru — karena index
     * unik parsial `operational_team_one_coordinator` menolak dua koordinator sekaligus.
     */
    private function operKoordinator(WorkOrder $workOrder, User $petugas, WorkOrderLog $log): void
    {
        if ($log->entry_type !== 'shift_handover' || $log->handover_to_id === null
            || $workOrder->coordinatorId() !== $petugas->id) {
            return;
        }

        $workOrder->operationalTeam()->where('user_id', $petugas->id)->update(['is_coordinator' => false]);
        $workOrder->operationalTeam()->where('user_id', $log->handover_to_id)->update(['is_coordinator' => true]);

        User::find($log->handover_to_id)->notify(new WorkOrderNotification(
            $workOrder,
            WorkOrderNotification::COORDINATOR,
            "Anda sekarang koordinator Work Order {$workOrder->reference_number} (oper shift dari {$petugas->name}).",
        ));
    }

    /**
     * Aturan status. Yang tidak punya transisi (`progress`, `shift_handover`, `obstacle`)
     * cuma boleh selagi pekerjaannya berjalan.
     */
    private function pastikanBoleh(WorkOrder $workOrder, string $jenis): void
    {
        $transisi = WorkOrder::LOG_TRANSITIONS[$jenis] ?? null;

        if ($transisi !== null) {
            [$dari] = $transisi;

            if ($workOrder->repair_status !== $dari) {
                throw new DomainException(
                    self::PESAN[$jenis] ?? 'Aksi ini tidak bisa dilakukan pada status pekerjaan sekarang.',
                    'repair_status',
                );
            }

            if ($jenis === 'complete') {
                $this->pastikanBuktiLengkap($workOrder);
            }

            return;
        }

        // `rework` ada di daftar enum tapi TIDAK PERNAH DITULIS SIAPA PUN — nol baris di
        // seluruh database, dan itu keputusan, bukan kelalaian.
        //
        // Pekerjaan yang dikembalikan sudah punya catatannya sendiri di
        // `work_order_reviews`: siapa yang mengembalikan, kapan, dan alasannya
        // (`rejection_notes`). Menulis baris log kedua untuk kejadian yang sama berarti
        // dua tempat untuk satu fakta, dan dua-duanya bisa saling bertentangan — alasan
        // yang sama dengan `wo_photo` yang cuma satu nilai dan `asset_catalog_id` yang
        // sengaja NULL untuk WO insiden.
        //
        // Layar Activity Log yang mau menandai "pekerjaan kembali ke saya" menggabungkan
        // `logs` dan `reviews` menurut waktunya — dua-duanya sudah ikut di
        // GET /work-orders/{id}.
        //
        // Ditolak dari sini, bukan dibiarkan lolos: kalau diterima, petugas bisa menyatakan
        // pekerjaannya sendiri perlu diulang tanpa ada yang mengevaluasi.
        if ($jenis === 'rework') {
            throw new DomainException(
                'Pengerjaan ulang ditentukan saat evaluasi Unit Maintenance, bukan dicatat dari lapangan.',
                'entry_type',
            );
        }

        if (! in_array($workOrder->repair_status, ['in_progress', 'rework'], true)) {
            throw new DomainException(
                'Pekerjaan ini belum berjalan. Terima tugasnya dan lewati verifikasi APD dulu.',
                'repair_status',
            );
        }
    }

    /**
     * Ajukan selesai ditolak kalau ketiga tahap bukti belum lengkap BERIKUT FOTONYA.
     *
     * Yang dijaga di sini layar evaluasi Fase 6: Unit Maintenance memutuskan diterima
     * atau dikerjakan ulang dengan MELIHAT fotonya. Pekerjaan yang bisa diajukan tanpa
     * foto membuat evaluator menekan "Diterima" pada layar kosong, dan biaya perbaikan
     * dibukukan atas bukti yang tidak ada.
     *
     * Konsekuensi untuk klien luring, dan ini harus masuk panduan FE: **unggahan foto
     * diselesaikan DULU, baru baris `complete` dikirim.** Urutan sebaliknya dijawab 422
     * berpesan, bukan 500 — jadi antrean sync-nya boleh mencoba lagi nanti tanpa
     * kehilangan apa pun.
     *
     * Beda perlakuan dengan `shift_handover` yang fotonya TIDAK dipaksa, dan bedanya
     * disengaja: oper shift terjadi saat jam kerja habis dan orangnya harus pulang —
     * memblokirnya menunggu unggahan berarti pekerjaan tidak bisa dioper sama sekali.
     * Ajukan selesai tidak punya tenggat sekeras itu dan boleh diulang kapan saja.
     */
    private function pastikanBuktiLengkap(WorkOrder $workOrder): void
    {
        // Pekerjaan yang pernah DIKEMBALIKAN: during dan after cuma dihitung sejak
        // pengembalian terakhir. Tanpa ini foto putaran pertama — yang justru ditolak
        // evaluator — cukup untuk mengajukan ulang tanpa satu foto baru pun (temuan FE,
        // 1 Okt). Review terakhir di WO yang masih berjalan pasti pengembalian: yang
        // Diterima menutup WO-nya.
        //
        // `before` dikecualikan (6 Okt): itu kondisi awal SEBELUM pekerjaan dimulai, dan
        // revisi tidak mengubahnya — yang ditolak evaluator hasil kerjanya. Revisi berarti
        // pekerjaan dilanjutkan: before → during → (revisi) → during → after, bukan mulai
        // lagi dari before. Foto lama tidak dibuang; cuma tidak dihitung sebagai syarat.
        $dikembalikan = $workOrder->reviews()->max('reviewed_at');

        $baris = $workOrder->logs()
            ->where('entry_type', 'progress')
            ->when($dikembalikan, fn ($q) => $q->where(fn ($w) => $w
                ->where('bda_stage', 'before')
                ->orWhere('logged_at', '>', $dikembalikan)))
            ->get(['uuid', 'bda_stage']);

        // DUA query yang membandingkan NILAI, bukan satu whereHas yang membandingkan
        // KOLOM. `work_order_logs.uuid` bertipe uuid native sementara
        // `documents.documentable_id` varchar(36) — polymorphic memang harus menampung
        // PK bigint maupun uuid. Postgres menolak `uuid = character varying` antar kolom
        // (SQLSTATE 42883), tapi menerima uuid dibandingkan dengan nilai string yang
        // di-bind. whereIn di bawah persis bentuk itu, dan itu juga sebabnya eager load
        // `logs.documents` selama ini baik-baik saja.
        $berfoto = Document::where('documentable_type', 'work_order_logs')
            ->whereIn('documentable_id', $baris->pluck('uuid')->all())
            ->pluck('documentable_id');

        $adaFotonya = $baris->whereIn('uuid', $berfoto)->pluck('bda_stage')->unique();

        $kurang = collect(config('enums.wo_bda_stage'))->diff($adaFotonya);

        if ($kurang->isNotEmpty()) {
            throw new DomainException(
                'Bukti foto belum lengkap: '.$kurang->implode(', ').'. Selesaikan unggahannya dulu.'
                    .($dikembalikan ? ' Pekerjaan ini pernah dikembalikan, jadi foto during dan after harus yang diambil sesudah pengembalian terakhir.' : ''),
                'bda_stage',
            );
        }
    }

    /**
     * Transisi status + jejak penyerahan antar badan hukum.
     */
    private function jalankanTransisi(WorkOrder $workOrder, User $petugas, string $jenis, Carbon $waktu): void
    {
        $transisi = WorkOrder::LOG_TRANSITIONS[$jenis] ?? null;

        if ($transisi === null) {
            return;
        }

        $ubah = ['repair_status' => $transisi[1]];

        if ($jenis === 'start') {
            // handed_over_at + received_by adalah jejak penyerahan ANTAR BADAN HUKUM —
            // JMTO menyerahkan, JMTM menerima. Bukan disposisi internal, jadi diisi
            // sekali dan tidak ditimpa waktu pekerjaan dikembalikan lalu dikerjakan
            // ulang: yang menerima pekerjaan itu tetap orang yang menerimanya pertama.
            $ubah += ['received_by' => $petugas->id, 'handed_over_at' => $waktu];
        }

        $workOrder->update($ubah);
    }

    /**
     * Dua kejadian lapangan yang ditunggu Unit Maintenance: pekerjaan diajukan selesai
     * (menunggu evaluasinya) dan kendala. Ke SEMUA Unit Maintenance aktif, bukan cuma
     * penerbitnya — siapa pun di Unit boleh mengevaluasi (WorkOrderPolicy::view).
     *
     * Kiriman ulang tidak sampai sini: sync() sudah menjawabnya di cek idempotensi.
     */
    private function beriTahuUnit(WorkOrder $workOrder, User $petugas, WorkOrderLog $log): void
    {
        [$event, $pesan] = match ($log->entry_type) {
            'complete' => [WorkOrderNotification::SUBMITTED,
                "Work Order {$workOrder->reference_number} diajukan selesai oleh {$petugas->name}, menunggu evaluasi."],
            'obstacle' => [WorkOrderNotification::OBSTACLE,
                "Kendala di Work Order {$workOrder->reference_number} ({$petugas->name}): {$log->reason}"
                    .($log->notes ? " — {$log->notes}" : '')],
            default => [null, null],
        };

        if ($event === null) {
            return;
        }

        Notification::send(
            User::where('role', 'maintenance_unit')->where('is_active', true)->get(),
            new WorkOrderNotification($workOrder, $event, $pesan),
        );
    }

    /**
     * Hari keberapa pekerjaan ini berjalan. Dihitung dari tanggal serah terima, bukan
     * diketik: pekerjaan lintas hari itu hal biasa, dan angka yang diketik orang yang
     * sedang kehujanan di bahu jalan tidak lebih dapat dipercaya daripada selisih dua
     * tanggal yang sudah tersimpan.
     */
    private function hariKe(WorkOrder $workOrder, Carbon $waktu): int
    {
        $mulai = $workOrder->handed_over_at;

        return $mulai === null ? 1 : $mulai->startOfDay()->diffInDays($waktu->copy()->startOfDay()) + 1;
    }

    /**
     * Pesan per transisi. Ditulis sebagai kalimat yang menyebut LANGKAH BERIKUTNYA, bukan
     * nama status — yang membacanya petugas di lapangan, bukan pembaca skema.
     */
    private const PESAN = [
        'start' => 'Tugas ini sudah diterima sebelumnya.',
        'apd_check' => 'Verifikasi APD cuma bisa dilakukan setelah tugas diterima dan sebelum pekerjaan dimulai.',
        'complete' => 'Pekerjaan ini belum berjalan, jadi belum ada yang bisa diajukan selesai.',
    ];
}
