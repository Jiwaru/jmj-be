<?php

namespace Database\Seeders;

use App\Models\AssetCatalog;
use App\Models\Document;
use App\Models\OperationalTeam;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderLog;
use App\Services\MaintenanceOfficer\WorkOrderLogService;
use App\Services\Shared\WorkOrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Work Order contoh — supaya layar Unit Maintenance punya sesuatu untuk dirender.
 *
 * Jalan SESUDAH IncidentSeeder dan urutannya tidak bisa dibalik: pemicu Work Order dari
 * insiden adalah `damage_ba` yang sudah disahkan, dan itu baru ada setelah laporan 3, 4,
 * dan 28 melewati IncidentVerificationService.
 *
 * BARISNYA DIBUAT LEWAT WorkOrderService, bukan insert langsung — alasan yang sama dengan
 * IncidentSeeder: nomor, penyalinan lokasi, penempelan damaged_items, dan penutupan
 * laporan semuanya lahir dari kode yang dipakai produksi. Kalau aturannya berubah, data
 * contoh ikut berubah, bukan jadi museum aturan lama.
 *
 * Sejak Fase 5 ada (3 Sep), transisi lapangan TIDAK LAGI ditulis tangan: `fase5()`
 * memanggil WorkOrderLogService, service yang sama yang dipakai aplikasi petugas. Dua
 * penulis untuk satu tabel berarti data contoh boleh melenceng dari aturan yang berlaku —
 * dan yang paling mahal, layar evaluasi Fase 6 bisa terlihat benar padahal jalannya tidak
 * mungkin ditempuh siapa pun di lapangan.
 *
 * SATU HAL yang masih ditulis tangan, dan itu sengaja: `target_date` yang sudah lewat
 * untuk WO manual. FormRequest melarang tanggal di masa lalu (`after_or_equal:today`) dan
 * memang harus; tapi kartu "Lewat Target" butuh setidaknya satu baris supaya angkanya
 * pernah terlihat bukan nol. Satu-satunya cara mendapatkannya adalah memutar waktunya
 * mundur SETELAH terbit, bukan melonggarkan validasinya.
 */
class WorkOrderSeeder extends Seeder
{
    /**
     * Awalan catatan WO manual buatan seeder ini, supaya `db:seed` boleh diulang tanpa
     * ikut menghapus WO manual yang dibuat orang lewat Postman selama mencoba-coba.
     */
    private const PENANDA = '[contoh seeder]';

    /** Judul kartu Activity Log per tahap bukti. */
    public const JUDUL = [
        'before' => 'Kondisi awal lokasi',
        'during' => 'Proses pengerjaan',
        'after' => 'Hasil akhir pekerjaan',
    ];

    public function run(): void
    {
        $hendra = User::where('identity_code', 'JMJ001')->firstOrFail();
        $regu = User::where('role', 'maintenance_officer')->orderBy('identity_code')->get();

        $service = app(WorkOrderService::class);

        // WO dari insiden sudah dibersihkan IncidentSeeder (dia yang menghapus laporannya,
        // jadi dia juga yang harus menyingkirkan WO yang menunjuk ke sana). Yang tersisa
        // untuk dibereskan di sini cuma WO manual — dia tidak menunjuk laporan mana pun,
        // jadi tidak ikut terbawa.
        $manual = WorkOrder::where('trigger_note', 'like', self::PENANDA.'%')->pluck('id');
        OperationalTeam::whereIn('work_order_id', $manual)->delete();
        WorkOrder::whereIn('id', $manual)->delete();

        // Bukti foto Fase 5 dibereskan TERPISAH, dan itu bukan kelalaian desain: relasi
        // polymorphic tidak punya foreign key, jadi baris `documents` TIDAK ikut terhapus
        // waktu work_order_logs-nya hilang. Tanpa dua baris ini, tiap `db:seed` menumpuk
        // baris yatim berikut berkasnya di disk — persis cacat yang ketemu 2 Sep di
        // SeederTest (46 MB).
        //
        // Ikut menghapus foto yang diunggah lewat Postman selama mencoba-coba. Itu
        // konsekuensi yang sama dengan penghapusan WO-nya sendiri, dan seeder ini memang
        // alat pengembangan, bukan data produksi.
        Document::where('documentable_type', 'work_order_logs')->delete();
        Storage::disk('local')->deleteDirectory('wo');

        // LAPORAN 3 SENGAJA DIBIARKAN TANPA WORK ORDER. Dari tiga laporan yang BA
        // Kerusakannya disahkan (3, 4, 28), yang ini ditinggalkan supaya antrean pekerjaan
        // TIDAK KOSONG — layar pertama Unit Maintenance justru antrean, dan seeder yang
        // menghabiskan seluruh antrean membuat layar itu tidak pernah terlihat berisi.
        // Sekalian: `POST /work-orders` bisa dicoba dari Postman tanpa menyiapkan apa pun.

        // ---- A. Baru terbit, belum diserahkan. Regu DUA orang — bentuk yang tidak
        //         terlihat kalau daftar pelaksananya cuma pernah berisi satu nama.
        //         Pemicunya preventif: WO manual persediaannya tak terbatas, sementara
        //         laporan ber-BA tervalidasi cuma tiga dan salah satunya harus tetap
        //         mengantre.
        $service->issue($hendra, [
            'trigger_source' => 'Preventif',
            'trigger_note' => self::PENANDA.' Jadwal preventif triwulan III',
            'asset_catalog_id' => self::aset('Guard Rail Beam (4000X312X2.7)'),
            'damage_description' => 'Pengecatan ulang guardrail KM 8+000 s.d. 8+500 arah A.',
            'location_type' => 'Jalur Utama',
            'km' => 8, 'm' => 0, 'route' => 'A', 'lane' => 'Bahu Jalan',
            'priority' => 'Rendah',
            'target_date' => now()->addDays(10)->setTime(16, 0)->toDateTimeString(),
            'assignee_ids' => [$regu[0]->id, $regu[1]->id],
            'coordinator_id' => $regu[0]->id,
        ]);

        // ---- B. Menunggu evaluasi, DAN sudah pernah dikembalikan sekali.
        //         Ini satu-satunya baris yang membuktikan riwayat evaluasi menumpuk dan
        //         tidak ditimpa: dua putaran, dua baris work_order_reviews.
        $b = $service->issue($hendra, [
            'trigger_source' => 'Insiden',
            'incident_report_id' => IncidentSeeder::uuid(28),
            'priority' => 'Sedang',
            'target_date' => now()->addDays(5)->toDateString(),
            'assignee_ids' => [$regu[0]->id, $regu[2]->id],
            // Koordinator = yang menerima tugas di fase5() di bawah, supaya kartu Tim
            // dan log "Pekerjaan Diterima" menyebut orang yang sama.
            'coordinator_id' => $regu[0]->id,
        ]);

        $this->fase5($b, $regu[0], 'Putaran pertama: beam terpasang, blocking piece menyusul.');
        $service->review($b, $hendra, [
            'meets_quality_standard' => false,
            'quantity_matches_log' => true,
            'outcome' => 'Dikerjakan Ulang',
            'rejection_notes' => 'Blocking piece kurang 1, dan foto after tidak memperlihatkan sambungan.',
            'revision_deadline' => now()->addDays(2)->setTime(21, 0)->toDateTimeString(),
        ]);
        // Jam evaluasinya dimundurkan ke DI ANTARA dua putaran. Log seeder ditulis dengan
        // jam lampau, sementara review() mencatat now() — tanpa ini foto putaran kedua
        // tercatat SEBELUM pengembaliannya, dan ajukan selesai ditolak karena cuma foto
        // sesudah pengembalian terakhir yang dihitung.
        $b->reviews()->update(['reviewed_at' => now()->subDays(3)->setTime(16, 0)]);
        $this->fase5($b, $regu[0], 'Putaran kedua: blocking piece terpasang, foto sambungan diambil ulang.', 2);

        // ---- C. Selesai dan ditutup — yang membuat laporan 4 jadi 'closed'.
        //         Sebelum Fase 6 ada, status itu ditulis tangan di IncidentSeeder. Sekarang
        //         dia lahir dari WorkOrderService::review(), jadi kalau penjaga
        //         penutupannya suatu saat berubah, seeder ini yang jatuh duluan.
        $c = $service->issue($hendra, [
            'trigger_source' => 'Insiden',
            'incident_report_id' => IncidentSeeder::uuid(4),
            'priority' => 'Rendah',
            'target_date' => now()->addDay()->toDateString(),
            'assignee_ids' => [$regu[2]->id],
            'coordinator_id' => $regu[2]->id,
        ]);

        $this->fase5($c, $regu[2], 'Guardrail terpasang kembali, lajur dibuka.');
        $service->review($c, $hendra, [
            'meets_quality_standard' => true,
            'quantity_matches_log' => true,
            'outcome' => 'Diterima',
            'rejection_notes' => null,
        ]);

        // ---- D. WO MANUAL, dan sudah lewat target.
        //         Temuan patroli tidak melalui Fase 1-3 sama sekali: tidak ada BA, tidak
        //         ada penagihan, biayanya ditanggung Perusahaan. Jenis asetnya WAJIB
        //         disebut di sini justru karena tidak ada damaged_items untuk dibaca.
        $d = $service->issue($hendra, [
            'trigger_source' => 'Temuan Patroli',
            'trigger_note' => self::PENANDA.' Temuan patroli shift B',
            // Jenis aset SENGAJA bukan guardrail: ini satu-satunya baris yang
            // membuktikan WO manual boleh menunjuk jenis lain, dan tap pertama
            // layar pemilihan aset memang punya dua kategori.
            'asset_catalog_id' => self::aset('Concrete Barrier, Tipe B (1 Muka)'),
            'damage_description' => 'Concrete barrier bahu KM 45+200 pecah ± 6 m, pelakunya tidak diketahui.',
            'location_type' => 'Jalur Utama',
            'km' => 45, 'm' => 200, 'route' => 'B', 'lane' => 'Bahu Jalan',
            'priority' => 'Sedang',
            'target_date' => now()->addDay()->toDateString(),
            // Dua orang supaya oper shift di bawah punya penerima dari regu yang sama.
            'assignee_ids' => [$regu[1]->id, $regu[0]->id],
            'coordinator_id' => $regu[1]->id,
        ]);

        $this->fase5($d, $regu[1], 'Pengecekan awal, lokasi dipasangi rambu pengaman sementara.', 1, selesai: false);

        // Dua cabang layar Fase 5 yang tidak dilewati alur normal, dan tanpa contoh di
        // sini FE tidak punya satu pun baris untuk merendernya: oper shift antar regu dan
        // lapor kendala. Ditaruh di WO D karena dia satu-satunya yang masih `in_progress`
        // — dua-duanya cuma boleh selagi pekerjaan berjalan.
        $catatan = app(WorkOrderLogService::class);

        $catatan->sync((string) Str::uuid(), $d, $regu[1], [
            'entry_type' => 'shift_handover',
            'reason' => 'Jam Kerja Shift Berakhir',
            // Ditulis KOORDINATOR (regu[1]) dan menyebut penerima → peran koordinator
            // pindah ke regu[0] (aturan 5 Okt). Satu-satunya contoh perpindahan itu.
            'handover_to_id' => $regu[0]->id,
            'notes' => 'Barrier pengganti sudah di lokasi, pemasangan dilanjutkan shift berikutnya.',
            'logged_at' => now()->subDays(2)->setTime(21, 45)->toDateTimeString(),
        ]);

        // Lapor kendala TIDAK melepas tugas — WO ini tetap `in_progress` sesudahnya, dan
        // itu yang membedakannya dari "menolak tugas" yang sengaja tidak dibangun.
        $catatan->sync((string) Str::uuid(), $d, $regu[1], [
            'entry_type' => 'obstacle',
            'reason' => 'Material Belum Tersedia',
            'notes' => 'Concrete barrier pengganti belum turun dari gudang pusat.',
            'logged_at' => now()->subDay()->setTime(9, 30)->toDateTimeString(),
        ]);

        // Ditulis tangan SETELAH terbit: FormRequest melarang target di masa lalu, dan itu
        // aturan yang benar untuk WO yang baru dibuat. Yang dibutuhkan di sini justru WO
        // yang sudah telanjur lewat — satu-satunya cara mendapatkannya adalah memutar
        // waktunya mundur, bukan melonggarkan validasinya.
        $d->update(['target_date' => now()->subDays(2)->toDateString()]);
    }

    /**
     * Baris katalog DISEBUT NAMANYA, bukan diambil `orderBy('id')->first()`.
     * Selama katalog cuma berisi guardrail, "baris pertama" kebetulan benar; begitu
     * jenis kedua masuk, kebetulan itu berhenti dan gejalanya WO yang menyebut aset
     * yang salah — tanpa satu pun error.
     */
    private static function aset(string $component): int
    {
        return AssetCatalog::where('component', $component)->firstOrFail()->id;
    }

    /**
     * Fase 5 dijalankan LEWAT SERVICE-nya, bukan ditulis ke tabel.
     *
     * Artinya data contoh ini menempuh gerbang yang sama dengan petugas sungguhan:
     * terima tugas (`new` -> `preparing`), verifikasi APD lengkap (`preparing` ->
     * `in_progress`), bukti Before-During-After berikut fotonya, baru boleh mengajukan
     * selesai. Kalau salah satu aturannya berubah, seeder ini yang jatuh duluan — itu
     * gunanya.
     *
     * `$hari` menggeser `logged_at`, bukan mengisi `day_number` — angka itu diturunkan
     * service dari jarak ke `handed_over_at`, dan seeder tidak punya hak menentukannya
     * sendiri.
     */
    private function fase5(WorkOrder $workOrder, User $petugas, string $catatan, int $hari = 1, bool $selesai = true): void
    {
        $logs = app(WorkOrderLogService::class);

        // Dibaca ulang dari database, BUKAN dipercaya apa adanya. WorkOrderService::review()
        // menulis lewat salinan yang dikunci sendiri, jadi $workOrder di tangan seeder masih
        // menyimpan status sebelum evaluasi — dan putaran kedua akan mengira pekerjaannya
        // belum pernah diterima.
        $workOrder->refresh();

        // Serah terima dimundurkan supaya pekerjaan lintas hari (putaran kedua WO B)
        // menghasilkan day_number 2 dari perhitungan yang sebenarnya, bukan dari angka
        // yang diketik seeder.
        $mulai = now()->subDays(3)->setTime(8, 0);
        $jam = fn (int $menit) => $mulai->copy()->addDays($hari - 1)->addMinutes($menit);

        // Putaran ULANG (pekerjaan dikembalikan Fase 6) melewati dua langkah ini: tugasnya
        // sudah diterima dan APD-nya sudah diverifikasi. Service akan menolak 422 kalau
        // dipaksa — dan penolakan itu justru yang membuktikan gerbangnya hidup.
        if ($workOrder->repair_status === 'new') {
            $logs->sync((string) Str::uuid(), $workOrder, $petugas, [
                'entry_type' => 'start',
                'logged_at' => $mulai->toDateTimeString(),
            ]);

            $logs->sync((string) Str::uuid(), $workOrder, $petugas, [
                'entry_type' => 'apd_check',
                'apd_items' => config('enums.wo_apd_items'),
                'logged_at' => $mulai->copy()->addMinutes(10)->toDateTimeString(),
            ]);
        }

        // Timeline bebas, bukan tiga slot tetap: pekerjaan bisa lintas hari dan lintas
        // shift, jadi before/during/after adalah TAHAP yang dicatat, bukan tiga baris
        // yang dijamin ada tepat sekali.
        foreach (['before' => 30, 'during' => 90, 'after' => 240] as $tahap => $menit) {
            $log = $logs->sync((string) Str::uuid(), $workOrder, $petugas, [
                'entry_type' => 'progress',
                'bda_stage' => $tahap,
                'title' => self::JUDUL[$tahap],
                'logged_at' => $jam($menit)->toDateTimeString(),
                'notes' => $tahap === 'after' ? $catatan : null,
            ]);

            // Fotonya WAJIB ada, bukan hiasan: `complete` ditolak service kalau ketiga
            // tahap belum berfoto. Layar evaluasi Fase 6 memang memutuskan diterima atau
            // dikerjakan ulang dengan melihat gambar ini.
            self::buktiFoto($log, $petugas, ucfirst($tahap).' - WO '.$workOrder->reference_number);
        }

        if (! $selesai) {
            return;
        }

        $logs->sync((string) Str::uuid(), $workOrder, $petugas, [
            'entry_type' => 'complete',
            'logged_at' => $jam(300)->toDateTimeString(),
        ]);
    }

    /**
     * Berkas contoh + barisnya di `documents`, menempel ke SATU BARIS LOG.
     *
     * Berkasnya betulan ditulis ke disk: `GET /documents/{id}` menjawab 404 kalau
     * berkasnya tidak ada, jadi baris tanpa file justru memberi FE kesan endpoint-nya
     * rusak. Polanya sama persis dengan DocumentStorageService::storeForWorkOrderLog(),
     * termasuk foldernya — `wo/{work_order_id}/`.
     */
    public static function buktiFoto(WorkOrderLog $log, User $uploader, string $label): void
    {
        $path = "wo/{$log->work_order_id}/".Str::uuid().'.png';

        Storage::disk('local')->put($path, PlaceholderImage::png($label));

        Document::create([
            'documentable_type' => 'work_order_logs',
            'documentable_id' => $log->uuid,
            'document_type' => 'wo_photo',
            'path' => $path,
            'mime_type' => 'image/png',
            'size_bytes' => Storage::disk('local')->size($path),
            'uploaded_by' => $uploader->id,
            'uploaded_at' => $log->logged_at,
        ]);
    }
}
