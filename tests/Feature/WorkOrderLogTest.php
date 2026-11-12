<?php

namespace Tests\Feature;

use App\Models\AssetCatalog;
use App\Models\AssetType;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderLog;
use App\Services\Shared\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 5 — aplikasi Petugas Maintenance.
 *
 * Yang dijaga di sini bukan "endpoint-nya menjawab 200", tapi bahwa URUTANNYA tidak bisa
 * dilompati: tugas diterima dulu, APD lengkap dulu, bukti berfoto dulu, baru boleh
 * diajukan selesai. Tiap gerbang yang bocor berakhir di tempat yang sama — Unit
 * Maintenance menekan "Diterima" di layar evaluasi yang kosong, dan biaya perbaikan
 * dibukukan atas bukti yang tidak ada.
 */
class WorkOrderLogTest extends TestCase
{
    use RefreshDatabase;

    private User $unit;

    private User $petugas;

    private User $reguLain;

    private AssetCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = $this->user('JMJ001', 'maintenance_unit');
        $this->petugas = $this->user('JMTM001', 'maintenance_officer');
        $this->reguLain = $this->user('JMTM002', 'maintenance_officer');

        $type = AssetType::create(['category' => 'support_facility', 'name' => 'Guardrail Kendaraan Tipe A']);
        $this->catalog = AssetCatalog::create([
            'name' => 'Guard Rail Beam',
            'asset_type_id' => $type->id,
            'component' => 'Guard Rail Beam (4000X312X2.7)',
            'unit_of_measurement' => 'bh',
        ]);

        Storage::fake('local');
    }

    // =====================================================================
    // Alur utuh
    // =====================================================================

    /**
     * Satu pekerjaan dari ditugaskan sampai diajukan selesai, lewat endpoint sungguhan.
     *
     * Yang dikunci bukan cuma status akhirnya tapi SETIAP perpindahannya — kalau suatu
     * saat `preparing` dilewati diam-diam, gerbang APD berhenti menjaga apa pun dan tidak
     * ada satu pun test lain yang akan merah.
     */
    public function test_alur_fase_5_dari_terima_sampai_ajukan_selesai(): void
    {
        $wo = $this->terbitkan();
        $this->assertSame('new', $wo->repair_status);

        $this->tulis('start')->assertOk();
        $this->assertSame('preparing', $wo->refresh()->repair_status);
        $this->assertSame($this->petugas->id, $wo->received_by);
        $this->assertNotNull($wo->handed_over_at);

        $this->tulis('apd_check', ['apd_items' => config('enums.wo_apd_items')])->assertOk();
        $this->assertSame('in_progress', $wo->refresh()->repair_status);

        foreach (['before', 'during', 'after'] as $tahap) {
            $uuid = $this->tulis('progress', ['bda_stage' => $tahap])->assertOk()->json('data.log.uuid');
            $this->unggahFoto($uuid)->assertOk();
        }

        $this->tulis('complete')->assertOk();
        $this->assertSame('submitted', $wo->refresh()->repair_status);
    }

    // =====================================================================
    // Gerbang APD
    // =====================================================================

    /**
     * Keputusan 3 Sep: tidak lengkap berarti tidak berangkat. Tiga bentuk "tidak lengkap"
     * yang semuanya harus ditolak — kurang, kosong, dan dicentang dua kali.
     *
     * Yang ketiga penting justru karena database TIDAK menolaknya: CHECK containment
     * `<@` meloloskan ["Helm Safety","Helm Safety"] karena tetap subset. Yang menjaganya
     * `distinct` di FormRequest, dan test inilah yang jatuh kalau aturan itu dibuang.
     */
    public function test_gerbang_apd_menolak_centang_yang_tidak_lengkap(): void
    {
        $this->terbitkan();
        $this->tulis('start')->assertOk();

        $semua = config('enums.wo_apd_items');
        $kembar = [$semua[0], $semua[0], $semua[2], $semua[3]];

        $this->tulis('apd_check', ['apd_items' => array_slice($semua, 0, 3)])->assertStatus(422);
        $this->tulis('apd_check', ['apd_items' => []])->assertStatus(422);
        $this->tulis('apd_check', ['apd_items' => $kembar])->assertStatus(422);

        $this->assertSame(0, WorkOrderLog::where('entry_type', 'apd_check')->count());
    }

    /**
     * Verifikasi APD tidak bisa dilakukan sebelum tugasnya diterima, dan tidak bisa
     * diulang setelah pekerjaan berjalan.
     */
    public function test_gerbang_apd_cuma_bisa_dilewati_sekali_dan_pada_waktunya(): void
    {
        $this->terbitkan();

        // Belum diterima.
        $this->tulis('apd_check', ['apd_items' => config('enums.wo_apd_items')])->assertStatus(422);

        $this->tulis('start')->assertOk();
        $this->tulis('apd_check', ['apd_items' => config('enums.wo_apd_items')])->assertOk();

        // Sudah lewat.
        $this->tulis('apd_check', ['apd_items' => config('enums.wo_apd_items')])->assertStatus(422);
    }

    /**
     * Gerbangnya berlaku untuk PEKERJAAN, bukan cuma untuk tombolnya: mencatat progres
     * sebelum APD diverifikasi juga ditolak. Kalau tidak, layar yang melompati gerbang
     * tetap bisa menulis seluruh bukti dan APD-nya jadi formalitas.
     */
    public function test_progres_tidak_bisa_dicatat_sebelum_apd_diverifikasi(): void
    {
        $this->terbitkan();
        $this->tulis('start')->assertOk();

        $this->tulis('progress', ['bda_stage' => 'before'])->assertStatus(422);
    }

    // =====================================================================
    // Ajukan selesai
    // =====================================================================

    /**
     * Bukti tanpa foto bukan bukti. Pesannya menyebut tahap mana yang kurang — 422 yang
     * cuma bilang "belum lengkap" memaksa petugas menebak apa yang harus dia lakukan
     * sambil berdiri di bahu jalan.
     */
    public function test_ajukan_selesai_ditolak_kalau_foto_belum_lengkap(): void
    {
        $wo = $this->siapKerja();

        $uuid = $this->tulis('progress', ['bda_stage' => 'before'])->json('data.log.uuid');
        $this->unggahFoto($uuid)->assertOk();
        $this->tulis('progress', ['bda_stage' => 'during'])->assertOk();
        $this->tulis('progress', ['bda_stage' => 'after'])->assertOk();

        $this->tulis('complete')
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($p) => str_contains($p, 'during') && str_contains($p, 'after'));

        $this->assertSame('in_progress', $wo->refresh()->repair_status);
    }

    /**
     * Pekerjaan yang dikembalikan evaluator harus membawa bukti BARU. Sebelum 1 Okt foto
     * putaran pertama — yang justru ditolak — sudah cukup untuk mengajukan ulang.
     *
     * Sejak 6 Okt yang wajib baru cuma during dan after: before itu kondisi awal, dan
     * revisi melanjutkan pekerjaan, bukan mengulangnya dari nol.
     */
    public function test_revisi_butuh_during_dan_after_baru_tapi_before_tetap_dari_putaran_pertama(): void
    {
        $wo = $this->siapKerja();
        $this->buktiLengkap();
        $this->tulis('complete')->assertOk();

        // Target revisi cuma untuk pengembalian, bukan untuk yang diterima.
        $this->review(['outcome' => 'Diterima', 'revision_deadline' => Carbon::now()->addDay()->toDateTimeString()])
            ->assertStatus(422)->assertJsonValidationErrors('revision_deadline');

        $this->review([
            'outcome' => 'Dikerjakan Ulang',
            'rejection_notes' => 'Foto after buram.',
            'revision_deadline' => Carbon::now()->addDay()->setTime(21, 0)->toDateTimeString(),
        ])->assertOk()->assertJsonPath('data.review.revision_deadline', fn ($v) => $v !== null);
        $this->assertSame('in_progress', $wo->refresh()->repair_status);

        $this->travel(2)->hours();

        $this->tulis('complete')
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($p) => str_contains($p, 'during, after') && ! str_contains($p, 'before'));

        $this->unggahFoto($this->tulis('progress', ['bda_stage' => 'during'])->json('data.log.uuid'))->assertOk();
        $this->tulis('complete')
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($p) => str_contains($p, 'belum lengkap: after.'));

        // Tanpa before baru: before putaran pertama tetap dihitung.
        $this->unggahFoto($this->tulis('progress', ['bda_stage' => 'after'])->json('data.log.uuid'))->assertOk();
        $this->tulis('complete')->assertOk();
        $this->assertSame('submitted', $wo->refresh()->repair_status);

        // Foto after yang ditolak tidak hilang — tetap ada di timeline.
        $this->assertSame(2, $wo->logs()->where('bda_stage', 'after')->count());
    }

    /**
     * Judul log, dan penerima oper shift yang harus rekan satu regu — orang di luar regu
     * tidak bisa melihat WO-nya, jadi oper ke dia sama dengan oper ke kekosongan.
     */
    public function test_judul_log_dan_penerima_oper_shift(): void
    {
        $this->siapKerja([$this->petugas->id, $this->reguLain->id]);
        $luar = $this->user('JMTM003', 'maintenance_officer');

        $this->tulis('progress', ['bda_stage' => 'before', 'title' => 'Pasang rambu'])
            ->assertOk()->assertJsonPath('data.log.title', 'Pasang rambu');

        $oper = ['reason' => 'Jam Kerja Shift Berakhir'];
        $this->tulis('shift_handover', $oper + ['handover_to_id' => $luar->id])->assertStatus(422)->assertJsonValidationErrors('handover_to_id');
        $this->tulis('shift_handover', $oper + ['handover_to_id' => $this->petugas->id])->assertStatus(422)->assertJsonValidationErrors('handover_to_id');
        $this->tulis('progress', ['bda_stage' => 'during', 'handover_to_id' => $this->reguLain->id])->assertStatus(422);

        $this->tulis('shift_handover', $oper + ['handover_to_id' => $this->reguLain->id])
            ->assertOk()->assertJsonPath('data.log.handover_to_id', $this->reguLain->id);

        $oper = collect($this->actingAs($this->petugas, 'sanctum')->getJson("/api/v1/work-orders/{$this->wo->id}")
            ->assertOk()->json('data.work_order.logs'))->firstWhere('entry_type', 'shift_handover');
        $this->assertSame($this->reguLain->name, $oper['handover_to']['name']);
    }

    /**
     * Daftar WO membawa yang dibutuhkan kartu dashboard tanpa memanggil detail per WO:
     * riwayat evaluasi, jabatan anggota, dan penanda koordinator.
     */
    public function test_daftar_wo_membawa_review_jabatan_dan_koordinator(): void
    {
        $this->actingAs($this->unit, 'sanctum')->postJson('/api/v1/work-orders', [
            'trigger_source' => 'Preventif',
            'asset_catalog_id' => $this->catalog->id,
            'damage_description' => 'Cat ulang guardrail.',
            'location_type' => 'Jalur Utama', 'km' => 8, 'route' => 'A',
            'priority' => 'Rendah',
            'target_date' => Carbon::tomorrow()->toDateString(),
            'assignee_ids' => [$this->petugas->id, $this->reguLain->id],
            'coordinator_id' => $this->reguLain->id,
        ])->assertOk();

        $kartu = $this->actingAs($this->petugas, 'sanctum')->getJson('/api/v1/work-orders')
            ->assertOk()->json('data.0');

        $this->assertSame([], $kartu['reviews']);
        $koordinator = collect($kartu['operational_team'])->firstWhere('is_coordinator', true);
        $this->assertSame($this->reguLain->id, $koordinator['user_id']);
        $this->assertSame('Petugas', $koordinator['user']['position']);
    }

    /**
     * Target berjam. Kiriman tanggal saja dibaca 23:59:59 hari itu — form lama tidak
     * patah dan target "hari ini" tidak ditolak. Koordinator harus anggota regunya.
     */
    public function test_target_berjam_dan_koordinator_harus_anggota_regu(): void
    {
        $this->freezeTime();
        $kirim = fn (array $o) => $this->actingAs($this->unit, 'sanctum')->postJson('/api/v1/work-orders', $o + [
            'trigger_source' => 'Preventif',
            'asset_catalog_id' => $this->catalog->id,
            'damage_description' => 'Cat ulang guardrail.',
            'location_type' => 'Jalur Utama', 'km' => 8, 'route' => 'A',
            'priority' => 'Rendah',
            'target_date' => Carbon::now()->addHours(3)->format('Y-m-d H:i:s'),
            'assignee_ids' => [$this->petugas->id],
        ]);

        $id = $kirim(['target_date' => Carbon::today()->toDateString()])->assertOk()->json('data.work_order.id');
        $this->assertSame('23:59:59', WorkOrder::find($id)->target_date->format('H:i:s'));

        $kirim(['target_date' => Carbon::now()->subMinute()->format('Y-m-d H:i:s')])
            ->assertStatus(422)->assertJsonValidationErrors('target_date');
        $kirim(['coordinator_id' => $this->reguLain->id])
            ->assertStatus(422)->assertJsonValidationErrors('coordinator_id');

        $id = $kirim([])->assertOk()->json('data.work_order.id');
        $this->assertSame(Carbon::now()->addHours(3)->format('H:i'), WorkOrder::find($id)->target_date->format('H:i'));
    }

    /**
     * Lonceng: ditugaskan → dikembalikan, milik akun sendiri, bisa ditandai dibaca.
     */
    public function test_notifikasi_lonceng_petugas(): void
    {
        $this->siapKerja();
        $this->buktiLengkap();
        $this->tulis('complete')->assertOk();
        $this->travel(1)->minutes();
        $this->review(['outcome' => 'Dikerjakan Ulang', 'rejection_notes' => 'Baut kurang satu.'])->assertOk();

        $lonceng = $this->actingAs($this->petugas, 'sanctum')->getJson('/api/v1/notifications')->assertOk()->json('data');

        $this->assertSame(2, $lonceng['unread_count']);
        $this->assertSame(['returned', 'assigned'], array_column(array_column($lonceng['notifications'], 'data'), 'event'));

        $id = $lonceng['notifications'][0]['id'];

        // Notifikasi orang lain tidak terjangkau — 404, bukan 403 yang membocorkan ada.
        $this->actingAs($this->reguLain, 'sanctum')->postJson("/api/v1/notifications/{$id}/read")->assertNotFound();

        $this->actingAs($this->petugas, 'sanctum')->postJson("/api/v1/notifications/{$id}/read")
            ->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->actingAs($this->petugas, 'sanctum')->postJson('/api/v1/notifications/read-all')
            ->assertOk()->assertJsonPath('data.unread_count', 0);
    }

    // =====================================================================
    // Koordinator (keputusan PO 5 Okt)
    // =====================================================================

    /**
     * Terima tugas dan ajukan selesai cuma oleh koordinator — 422, bukan 403, supaya
     * antrean luring HP koordinator baru bisa mencoba lagi. Log lain bebas semua anggota.
     */
    public function test_terima_dan_ajukan_selesai_hanya_oleh_koordinator(): void
    {
        $this->terbitkan([$this->petugas->id, $this->reguLain->id]);   // koordinator: petugas

        $this->tulis('start', [], null, $this->reguLain)
            ->assertStatus(422)->assertJsonPath('message', 'Hanya koordinator regu yang bisa menerima tugas ini.');
        $this->tulis('start')->assertOk();

        // APD, progres, dan foto boleh anggota biasa.
        $this->tulis('apd_check', ['apd_items' => config('enums.wo_apd_items')], null, $this->reguLain)->assertOk();
        foreach (['before', 'during', 'after'] as $tahap) {
            $uuid = $this->tulis('progress', ['bda_stage' => $tahap], null, $this->reguLain)->assertOk()->json('data.log.uuid');
            $this->actingAs($this->reguLain, 'sanctum')->postJson("/api/v1/work-order-logs/{$uuid}/documents", [
                'file_uuid' => (string) Str::uuid(),
                'file' => UploadedFile::fake()->image('bukti.jpg'),
            ])->assertOk();
        }

        $this->tulis('complete', [], null, $this->reguLain)->assertStatus(422);
        $this->tulis('complete')->assertOk();
    }

    /**
     * Koordinator berganti LEWAT OPER SHIFT yang ditulisnya sendiri. Oper shift anggota
     * biasa tidak memindahkan peran; kiriman ulang tidak memindahkannya dua kali.
     */
    public function test_koordinator_berganti_lewat_oper_shift_yang_ditulisnya_sendiri(): void
    {
        $ketiga = $this->user('JMTM003', 'maintenance_officer');
        $this->siapKerja([$this->petugas->id, $this->reguLain->id, $ketiga->id]);
        $oper = fn (User $dari, User $ke, ?string $uuid = null) => $this->tulis(
            'shift_handover', ['reason' => 'Jam Kerja Shift Berakhir', 'handover_to_id' => $ke->id], $uuid, $dari,
        );

        // Anggota biasa mengoper: tercatat, peran TIDAK pindah.
        $oper($this->reguLain, $ketiga)->assertOk();
        $this->assertSame($this->petugas->id, $this->wo->coordinatorId());

        // Koordinator mengoper: peran pindah, tetap tepat satu koordinator.
        $uuid = (string) Str::uuid();
        $oper($this->petugas, $this->reguLain, $uuid)->assertOk();
        $this->assertSame($this->reguLain->id, $this->wo->coordinatorId());
        $this->assertSame(1, $this->wo->operationalTeam()->where('is_coordinator', true)->count());
        $this->assertContains('coordinator', $this->reguLain->notifications()->get()->pluck('data.event'));

        // Kiriman ulang (sinyal putus) tidak mengembalikan/menggeser peran.
        $oper($this->petugas, $this->reguLain, $uuid)->assertOk();
        $this->assertSame($this->reguLain->id, $this->wo->coordinatorId());

        // Koordinator lama sekarang anggota biasa.
        $this->buktiLengkap();
        $this->tulis('complete')->assertStatus(422);
        $this->tulis('complete', [], null, $this->reguLain)->assertOk();
    }

    /**
     * Keputusan PO 5 Okt: outcome tinggal dua, dan alasan kendala bertambah satu. Dua-duanya
     * butuh CHECK baru di database — test ini yang jatuh kalau migration-nya terlupa (500).
     */
    public function test_outcome_dikembalikan_ke_regu_ditolak_dan_arus_lalin_diterima(): void
    {
        $this->siapKerja();

        $this->tulis('obstacle', ['reason' => 'Arus Lalu Lintas Padat'])->assertOk();

        $this->buktiLengkap();
        $this->tulis('complete')->assertOk();
        $this->review(['outcome' => 'Dikembalikan ke Regu', 'rejection_notes' => 'Kurang baut.'])
            ->assertStatus(422)->assertJsonValidationErrors('outcome');
        $this->review(['outcome' => 'Dikerjakan Ulang', 'rejection_notes' => 'Kurang baut.'])->assertOk();
    }

    // =====================================================================
    // Luring
    // =====================================================================

    /**
     * IDEMPOTENSI. Ini satu-satunya alasan endpoint-nya PUT dan bukan POST: di lapangan,
     * "kirim" berarti sync, dan sync yang putus di tengah akan diulang klien. POST
     * melahirkan baris kedua tiap kali sinyal jelek — dan garis waktu dengan dua baris
     * "before" pada jam yang sama tidak bisa dibedakan dari pekerjaan yang memang
     * difoto dua kali.
     */
    public function test_kiriman_ulang_dengan_uuid_sama_tidak_melahirkan_baris_kedua(): void
    {
        $this->siapKerja();
        $uuid = (string) Str::uuid();

        $pertama = $this->tulis('progress', ['bda_stage' => 'before'], $uuid)->assertOk();
        $kedua = $this->tulis('progress', ['bda_stage' => 'before'], $uuid)->assertOk();

        $this->assertSame(1, WorkOrderLog::where('entry_type', 'progress')->count());
        $this->assertSame($pertama->json('data.log.uuid'), $kedua->json('data.log.uuid'));
    }

    /**
     * Kiriman ulang `start` juga harus dijawab 200, bukan 422 — dan itu bukan detail
     * kecil. Retry datang SESUDAH transisinya jalan, jadi statusnya sudah bukan `new`
     * lagi; kalau aturan status diperiksa sebelum idempotensi, HP akan mengulanginya
     * selamanya dan antrean sync-nya tidak pernah kosong.
     */
    public function test_kiriman_ulang_yang_sudah_mengubah_status_tetap_dijawab_200(): void
    {
        $this->terbitkan();
        $uuid = (string) Str::uuid();

        $this->tulis('start', [], $uuid)->assertOk();
        $this->tulis('start', [], $uuid)->assertOk();

        $this->assertSame(1, WorkOrderLog::where('entry_type', 'start')->count());
    }

    /**
     * `shift` dan `day_number` DITURUNKAN dari `logged_at`, tidak diterima dari layar.
     * Alasannya sama dengan shift di laporan insiden (keputusan 2 Sep): shift itu fungsi
     * dari jam, dan menerimanya dari klien cuma membuka kemungkinan shift melenceng dari
     * jam pada baris yang sama.
     */
    public function test_shift_dan_hari_diturunkan_dari_waktu_bukan_dari_kiriman(): void
    {
        $this->terbitkan();

        // Serah terima dimundurkan dua hari supaya pencatatan sehari sesudahnya tetap
        // berada di MASA LALU — `before_or_equal:now` memang menolak yang tidak.
        $mulai = Carbon::now()->subDays(2)->setTime(8, 0);
        $this->tulis('start', ['logged_at' => $mulai->toDateTimeString()])->assertOk();
        $this->tulis('apd_check', [
            'apd_items' => config('enums.wo_apd_items'),
            'logged_at' => $mulai->copy()->addMinutes(10)->toDateTimeString(),
        ])->assertOk();

        // shift dan day_number dikirim ngawur; harus diabaikan sama sekali.
        $this->tulis('progress', [
            'bda_stage' => 'before',
            'shift' => '3',
            'day_number' => 99,
            'logged_at' => $mulai->copy()->addDay()->setTime(15, 30)->toDateTimeString(),
        ])->assertOk();

        $log = WorkOrderLog::where('entry_type', 'progress')->firstOrFail();

        $this->assertSame('2', $log->shift, 'jam 15:30 jatuh di shift 2');
        $this->assertSame(2, $log->day_number, 'sehari setelah serah terima berarti hari ke-2');
    }

    // =====================================================================
    // Oper shift dan lapor kendala
    // =====================================================================

    /**
     * Alasan berbentuk PILIHAN, dan daftarnya berbeda antara oper shift dan lapor
     * kendala walau kolomnya sama. Daftar yang tertukar lolos kalau CHECK-nya tidak
     * bersyarat — dan gejalanya baru terlihat berbulan-bulan kemudian, waktu ada yang
     * mencoba menghitung berapa kali pekerjaan molor karena material.
     */
    public function test_alasan_oper_shift_dan_kendala_tidak_bisa_tertukar(): void
    {
        $this->siapKerja();

        $this->tulis('shift_handover', ['reason' => 'Material Belum Tersedia'])->assertStatus(422);
        $this->tulis('obstacle', ['reason' => 'Jam Kerja Shift Berakhir'])->assertStatus(422);

        $this->tulis('shift_handover', ['reason' => 'Jam Kerja Shift Berakhir'])->assertOk();
        $this->tulis('obstacle', ['reason' => 'Material Belum Tersedia'])->assertOk();
    }

    /**
     * "Lainnya" tanpa penjelasan tidak memberi tahu siapa pun apa yang terjadi. Tidak ada
     * kolom pendamping seperti `causer_other` — `notes` di baris yang sama sudah teks
     * bebas, dan dia yang jadi wajib.
     */
    public function test_alasan_lainnya_mewajibkan_catatan(): void
    {
        $this->siapKerja();

        $this->tulis('obstacle', ['reason' => 'Lainnya'])->assertStatus(422);
        $this->tulis('obstacle', ['reason' => 'Lainnya', 'notes' => 'Ada demo di pintu masuk.'])->assertOk();
    }

    /**
     * Keputusan 3 Sep: melaporkan kendala TIDAK melepas tugas. Yang digambar Figma
     * sebagai "Tolak / Lapor Kendala" dibangun sebagai lapor kendala saja — menolak
     * penugasan butuh status baru dan menyentuh Mentok #4 yang belum dijawab siapa pun.
     */
    public function test_lapor_kendala_tidak_mengubah_status_pekerjaan(): void
    {
        $wo = $this->siapKerja();

        $this->tulis('obstacle', ['reason' => 'Cuaca Tidak Mendukung'])->assertOk();

        $this->assertSame('in_progress', $wo->refresh()->repair_status);
        $this->assertSame($this->petugas->id, $wo->received_by);
    }

    // =====================================================================
    // Batas kepemilikan
    // =====================================================================

    /**
     * Regu sebelah tidak bisa menulis apa pun ke pekerjaan yang bukan miliknya, walau
     * role-nya sama persis. Role menjawab "boleh pakai aplikasi ini"; yang menjawab
     * "pekerjaan siapa ini" cuma keanggotaan `operational_team`.
     */
    public function test_petugas_regu_lain_tidak_bisa_menulis_log(): void
    {
        $wo = $this->terbitkan();

        $this->actingAs($this->reguLain, 'sanctum')
            ->putJson('/api/v1/work-order-logs/'.Str::uuid(), [
                'work_order_id' => $wo->id,
                'entry_type' => 'start',
                'logged_at' => Carbon::now()->subHour()->toDateTimeString(),
            ])
            ->assertStatus(403);

        $this->assertSame(0, WorkOrderLog::count());
    }

    /**
     * Unit Maintenance BOLEH melihat semuanya tapi TIDAK boleh menulis garis waktunya.
     * Kalau dia bisa, bukti yang dia evaluasi sendiri di Fase 6 bisa jadi bukti yang dia
     * tulis sendiri — dan evaluasi kehilangan seluruh artinya.
     */
    public function test_unit_maintenance_tidak_boleh_menulis_garis_waktu(): void
    {
        $wo = $this->terbitkan();

        $this->actingAs($this->unit, 'sanctum')
            ->putJson('/api/v1/work-order-logs/'.Str::uuid(), [
                'work_order_id' => $wo->id,
                'entry_type' => 'start',
                'logged_at' => Carbon::now()->subHour()->toDateTimeString(),
            ])
            ->assertStatus(403);
    }

    /**
     * `rework` ada di daftar enum tapi bukan milik aplikasi lapangan — barisnya ditulis
     * saat evaluasi Fase 6. Diterima dari HP, petugas bisa menyatakan pekerjaannya
     * sendiri perlu diulang tanpa ada yang mengevaluasi.
     */
    public function test_baris_pengerjaan_ulang_tidak_bisa_ditulis_dari_lapangan(): void
    {
        $this->siapKerja();

        $this->tulis('rework')->assertStatus(422);
    }

    // =====================================================================
    // Bekal luring
    // =====================================================================

    /**
     * Bundel luring petugas maintenance BERBEDA ISINYA dari milik petugas patroli, di
     * route yang sama.
     *
     * Yang dijaga di sini dua-duanya sekaligus, dan itu disengaja: kelima daftar `wo_*`
     * HARUS ada (tanpa itu dropdown APD dan alasan kosong di lapangan, dan formnya tidak
     * bisa diisi sama sekali), sementara katalog dan harga HARUS TIDAK ada (petugas
     * maintenance tidak menghitung uang — biaya dihitung Fase 6 dari damaged_items).
     *
     * Saringannya kebalikan persis dari milik patroli. Ditulis sebagai dua penegasan
     * karena melencengnya bisa ke dua arah, dan yang pertama gagalnya di bahu jalan.
     */
    /**
     * KONTRAK `options`: setiap grup berbentuk DAFTAR, untuk role mana pun. FE memanggil
     * .map() ke tiap grup — satu grup berbentuk objek membuat seluruh pilihan gagal
     * tersimpan di HP, dan gerbang APD tidak bisa dilewati (insiden 2 Okt,
     * `wo_reason_descriptions`). Test ini yang jatuh kalau peta masuk `options` lagi.
     */
    public function test_setiap_grup_options_di_bekal_luring_berbentuk_daftar(): void
    {
        foreach ([$this->petugas, $this->user('JMTO001', 'field_officer')] as $orang) {
            $options = $this->actingAs($orang, 'sanctum')
                ->getJson('/api/v1/sync/bootstrap')->assertOk()->json('data.options');

            foreach ($options as $grup => $isi) {
                $this->assertTrue(
                    is_array($isi) && array_is_list($isi),
                    "options.{$grup} untuk {$orang->role} bukan daftar — FE .map() akan gagal.",
                );
            }
        }
    }

    public function test_bekal_luring_petugas_maintenance_cuma_daftar_pilihan_fase_5(): void
    {
        $data = $this->actingAs($this->petugas, 'sanctum')
            ->getJson('/api/v1/sync/bootstrap')->assertOk()->json('data');

        $this->assertSame(['options', 'option_descriptions'], array_keys($data));
        $this->assertSame([
            'wo_trigger_source', 'wo_priority', 'wo_review_outcome', 'wo_log_entry_type',
            'wo_bda_stage', 'wo_apd_items', 'wo_handover_reason', 'wo_obstacle_reason',
        ], array_keys($data['options']));

        // Keterangan dropdown alasan: DI LUAR `options`, dan setiap alasan punya kalimatnya.
        foreach ([...config('enums.wo_handover_reason'), ...config('enums.wo_obstacle_reason')] as $alasan) {
            $this->assertArrayHasKey($alasan, $data['option_descriptions']);
        }

        // Yang dipakai langsung layar Fase 5 — isinya, bukan cuma ada kuncinya.
        $this->assertSame(config('enums.wo_apd_items'), $data['options']['wo_apd_items']);
        $this->assertContains('Lainnya', $data['options']['wo_handover_reason']);
    }

    /**
     * Role lain TIDAK ikut berubah. Bundel patroli tetap membawa katalog dan harga, dan
     * tetap TIDAK membawa satu pun daftar `wo_*` — kalau saringannya suatu saat terbalik,
     * HP petugas patroli mulai mengunduh daftar yang tidak pernah dia pakai.
     */
    public function test_bekal_luring_petugas_patroli_tidak_ikut_berubah(): void
    {
        $patroli = $this->user('JMTO001', 'field_officer');

        $data = $this->actingAs($patroli, 'sanctum')
            ->getJson('/api/v1/sync/bootstrap')->assertOk()->json('data');

        $this->assertArrayHasKey('asset_catalog', $data);
        $this->assertArrayHasKey('catalog_prices', $data);
        $this->assertArrayHasKey('officers', $data);

        foreach (array_keys($data['options']) as $kunci) {
            $this->assertStringStartsNotWith('wo_', $kunci);
        }
    }

    // =====================================================================
    // Bantuan
    // =====================================================================

    private function user(string $code, string $role): User
    {
        return User::create([
            'identity_code' => $code,
            'name' => "Orang {$code}",
            'position' => 'Petugas',
            'entity' => 'jmtm',
            'role' => $role,
            'is_active' => true,
            'password' => 'rahasia',
        ]);
    }

    private WorkOrder $wo;

    /** WO manual, supaya test ini tidak ikut menyeret seluruh Fase 1-3. */
    private function terbitkan(?array $regu = null): WorkOrder
    {
        return $this->wo = app(WorkOrderService::class)->issue($this->unit, [
            'trigger_source' => 'Temuan Patroli',
            'asset_catalog_id' => $this->catalog->id,
            'damage_description' => 'Lampu PJU KM 45+200 mati.',
            'location_type' => 'Jalur Utama',
            'km' => 45, 'm' => 200, 'route' => 'B', 'lane' => 'Bahu Jalan',
            'priority' => 'Sedang',
            'target_date' => Carbon::tomorrow()->toDateString(),
            'assignee_ids' => $regu ?? [$this->petugas->id],
            'coordinator_id' => $this->petugas->id,
        ]);
    }

    /** Sudah melewati terima + gerbang APD, siap mencatat progres. */
    private function siapKerja(?array $regu = null): WorkOrder
    {
        $wo = $this->terbitkan($regu);

        $this->tulis('start')->assertOk();
        $this->tulis('apd_check', ['apd_items' => config('enums.wo_apd_items')])->assertOk();

        return $wo->refresh();
    }

    private function tulis(string $jenis, array $extra = [], ?string $uuid = null, ?User $sebagai = null)
    {
        return $this->actingAs($sebagai ?? $this->petugas, 'sanctum')
            ->putJson('/api/v1/work-order-logs/'.($uuid ?? Str::uuid()), $extra + [
                'work_order_id' => $this->wo->id,
                'entry_type' => $jenis,
                'logged_at' => Carbon::now()->subHour()->toDateTimeString(),
            ]);
    }

    /** Ketiga tahap, masing-masing berfoto. */
    private function buktiLengkap(): void
    {
        foreach (['before', 'during', 'after'] as $tahap) {
            $this->unggahFoto($this->tulis('progress', ['bda_stage' => $tahap])->json('data.log.uuid'))->assertOk();
        }
    }

    private function review(array $data)
    {
        return $this->actingAs($this->unit, 'sanctum')
            ->postJson("/api/v1/work-orders/{$this->wo->id}/review", $data + [
                'meets_quality_standard' => false,
                'quantity_matches_log' => true,
                'cost_amount' => 100000,
            ]);
    }

    private function unggahFoto(string $logUuid)
    {
        return $this->actingAs($this->petugas, 'sanctum')
            ->postJson("/api/v1/work-order-logs/{$logUuid}/documents", [
                'file_uuid' => (string) Str::uuid(),
                'file' => UploadedFile::fake()->image('bukti.jpg'),
            ]);
    }

    /**
     * Lonceng Unit Maintenance: kendala dan ajukan selesai sampai ke SEMUA Unit aktif,
     * tidak ke petugas, dan kiriman ulang log yang sama tidak berbunyi dua kali.
     */
    public function test_notifikasi_lonceng_unit_maintenance(): void
    {
        $unitLain = $this->user('JMJ002', 'maintenance_unit');
        $nonaktif = $this->user('JMJ003', 'maintenance_unit');
        $nonaktif->update(['is_active' => false]);

        $this->siapKerja();
        $kendala = (string) Str::uuid();
        $this->tulis('obstacle', ['reason' => 'Lainnya', 'notes' => 'Hujan deras.'], $kendala)->assertOk();
        $this->tulis('obstacle', ['reason' => 'Lainnya', 'notes' => 'Hujan deras.'], $kendala)->assertOk();
        $this->buktiLengkap();
        $this->tulis('complete')->assertOk();

        foreach ([$this->unit, $unitLain] as $unit) {
            $this->assertEqualsCanonicalizing(['submitted', 'obstacle'], $unit->notifications()->get()->pluck('data.event')->all());
        }
        $this->assertSame(0, $nonaktif->notifications()->count());
        $this->assertNotContains('submitted', $this->petugas->notifications()->get()->pluck('data.event'));
        $this->assertStringEndsWith('Lainnya — Hujan deras.', $this->unit->notifications()->get()->firstWhere('data.event', 'obstacle')->data['message']);
    }

    /**
     * Paginasi lonceng: 50 per halaman, `?page=` untuk yang lebih lama, dan unread_count
     * tetap menghitung SEMUA notifikasi, bukan cuma halaman yang sedang dibuka.
     */
    public function test_notifikasi_berpaginasi_dan_unread_count_menghitung_semua(): void
    {
        foreach (range(1, 55) as $i) {
            $this->petugas->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'test',
                'data' => ['event' => 'assigned', 'message' => "Notifikasi {$i}"],
                'created_at' => Carbon::now()->addSeconds($i),
            ]);
        }

        $satu = $this->actingAs($this->petugas, 'sanctum')->getJson('/api/v1/notifications')->assertOk()->json('data');
        $this->assertCount(50, $satu['notifications']);
        $this->assertSame('Notifikasi 55', $satu['notifications'][0]['data']['message']);
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 50, 'total' => 55], $satu['pagination']);
        $this->assertSame(55, $satu['unread_count']);

        $dua = $this->actingAs($this->petugas, 'sanctum')->getJson('/api/v1/notifications?page=2')->assertOk()->json('data');
        $this->assertSame(['Notifikasi 5', 'Notifikasi 4', 'Notifikasi 3', 'Notifikasi 2', 'Notifikasi 1'],
            array_column(array_column($dua['notifications'], 'data'), 'message'));
        $this->assertSame(55, $dua['unread_count']);
    }

    /** Ganti regu: yang masuk dapat `assigned`, yang keluar dapat `removed`. */
    public function test_notifikasi_dikeluarkan_dari_regu(): void
    {
        $this->terbitkan();

        app(WorkOrderService::class)->replaceTeam($this->wo, [$this->reguLain->id], $this->reguLain->id);

        $this->assertEqualsCanonicalizing(['removed', 'assigned'], $this->petugas->notifications()->get()->pluck('data.event')->all());
        $this->assertSame(['assigned'], $this->reguLain->notifications()->get()->pluck('data.event')->all());
    }
}
