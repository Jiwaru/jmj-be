<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Jalur unggahnya sendiri diuji di IncidentSyncTest. Di sini fokusnya hak akses:
 * berkas ini berisi foto KTP dan NIK pihak ketiga, dan disk 'local' tidak punya
 * URL statis — jadi controller inilah satu-satunya penjaganya.
 */
class DocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    private string $uuid;

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->officer = $this->user('JMTO001');
        $this->uuid = (string) Str::uuid();

        IncidentReport::create([
            'uuid' => $this->uuid,
            'user_id' => $this->officer->id,
            'officer_2_id' => $this->user('JMTO002')->id,
            'date_time' => '2026-08-21 14:20:00',
            'causer' => 'Pecah Ban',
            'analyze' => 'Rusak Berat',
            'report_status' => 'pending_verification',
            'sync_status' => 'sent',
        ]);

        $path = "insiden/{$this->uuid}/".Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'isi-foto-tkp');

        $this->document = Document::create([
            'documentable_type' => 'incident_report',
            'documentable_id' => $this->uuid,
            'document_type' => 'scene_photo',
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 12,
            'uploaded_by' => $this->officer->id,
            'uploaded_at' => now(),
        ]);
    }

    private function user(string $code, string $role = 'field_officer'): User
    {
        return User::create([
            'identity_code' => $code,
            'name' => "Petugas {$code}",
            'entity' => 'jmto',
            'role' => $role,
            'is_active' => true,
            'password' => 'rahasia',
        ]);
    }

    public function test_the_owner_can_fetch_the_file_back(): void
    {
        $this->actingAs($this->officer, 'sanctum')
            ->get("/api/v1/documents/{$this->document->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_the_file_is_not_reachable_without_a_token(): void
    {
        $this->getJson("/api/v1/documents/{$this->document->id}")->assertStatus(401);
    }

    public function test_another_officer_cannot_fetch_someone_elses_photo(): void
    {
        // JMTO002 sudah dipakai setUp() sebagai officer_2_id laporan ini.
        $this->actingAs($this->user('JMTO099'), 'sanctum')
            ->getJson("/api/v1/documents/{$this->document->id}")
            ->assertStatus(403);
    }

    /**
     * Sejak Fase 2, MCS Supervisor MELIHAT dokumen laporan siapa pun — kotak "Bukti
     * Lapangan" di layar verifikasi justru itu yang diperiksanya sebelum mengesahkan
     * BA, dan antreannya memang berisi laporan orang lain.
     */
    public function test_a_supervisor_can_view_any_incident_document(): void
    {
        $this->actingAs($this->user('JMTO009', 'mcs_supervisor'), 'sanctum')
            ->get("/api/v1/documents/{$this->document->id}")
            ->assertOk();
    }

    /**
     * Tapi TIDAK menghapusnya. Yang salah unggah petugasnya, dan dia yang tahu berkas
     * mana yang keliru — MCS yang menghapus berarti membuang bukti milik laporan
     * orang lain tanpa yang bersangkutan tahu.
     */
    public function test_a_supervisor_cannot_delete_someone_elses_document(): void
    {
        $this->actingAs($this->user('JMTO009', 'mcs_supervisor'), 'sanctum')
            ->deleteJson("/api/v1/documents/{$this->document->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('documents', ['id' => $this->document->id]);
    }

    public function test_a_row_whose_file_vanished_from_disk_is_a_404_not_a_500(): void
    {
        Storage::disk('local')->delete($this->document->path);

        $this->actingAs($this->officer, 'sanctum')
            ->getJson("/api/v1/documents/{$this->document->id}")
            ->assertStatus(404);
    }

    public function test_the_owner_can_delete_a_wrongly_uploaded_photo(): void
    {
        $path = $this->document->path;

        $this->actingAs($this->officer, 'sanctum')
            ->deleteJson("/api/v1/documents/{$this->document->id}")
            ->assertOk()->assertJsonPath('success', true);

        $this->assertSame(0, Document::count());
        Storage::disk('local')->assertMissing($path);
    }

    /**
     * Yang diuji bukan "ada barisnya", tapi bahwa `path` masih terbaca SETELAH berkas
     * dan barisnya sama-sama hilang — itu satu-satunya bekas bahwa foto itu pernah ada.
     *
     * Tanpa ini petugas bisa mengunggah foto yang memperlihatkan sesuatu yang tidak
     * menguntungkan lalu menghapusnya sebelum MCS melihat, dan sistem tidak pernah tahu.
     */
    public function test_deleting_a_photo_leaves_a_trail_that_outlives_the_file(): void
    {
        $path = $this->document->path;

        $this->actingAs($this->officer, 'sanctum')
            ->deleteJson("/api/v1/documents/{$this->document->id}")
            ->assertOk();

        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, Document::count());

        $log = ActivityLog::sole();
        $this->assertSame('document_deleted', $log->action);
        // Subjeknya LAPORAN, bukan dokumennya: auditable_id yang menunjuk baris yang
        // sudah tidak ada tidak bisa ditelusuri siapa pun.
        $this->assertSame('incident_report', $log->auditable_type);
        $this->assertSame($this->uuid, $log->auditable_id);
        $this->assertSame($this->officer->id, $log->user_id);
        $this->assertSame($path, $log->old_values['path']);
        $this->assertSame('scene_photo', $log->old_values['document_type']);
        // Penghapusan mengisi old_values saja — sejajar dengan `correction` yang
        // mengisi keduanya.
        $this->assertNull($log->new_values);
    }

    public function test_another_officer_cannot_delete_someone_elses_photo(): void
    {
        // JMTO002 sudah dipakai setUp() sebagai officer_2_id laporan ini.
        $this->actingAs($this->user('JMTO099'), 'sanctum')
            ->deleteJson("/api/v1/documents/{$this->document->id}")
            ->assertStatus(403);

        $this->assertSame(1, Document::count());
    }

    /**
     * Regresi: mime_type pernah diambil dari header multipart kiriman HP. PNG asli
     * yang dideklarasikan 'text/html' tersimpan apa adanya, lalu dikirim balik
     * sebagai Content-Type: text/html — browser merendernya sebagai HTML, dan HTML
     * itu bisa diselipkan ke dalam PNG yang tetap lolos aturan mimes:.
     *
     * Sejak 6 Okt fotonya dikompres jadi JPEG, jadi yang benar sekarang image/jpeg —
     * tetap dari berkas yang DISIMPAN, tidak pernah dari header kiriman.
     */
    public function test_a_spoofed_content_type_header_never_reaches_the_download(): void
    {
        $real = UploadedFile::fake()->image('tkp.png');

        $this->actingAs($this->officer, 'sanctum')->post(
            "/api/v1/incident-reports/{$this->uuid}/documents",
            [
                'file_uuid' => (string) Str::uuid(),
                'document_type' => 'scene_photo',
                'file' => new UploadedFile($real->getPathname(), 'tkp.png', 'text/html', null, true),
            ],
            ['Accept' => 'application/json'],
        )->assertOk();

        $uploaded = Document::latest('id')->first();
        $this->assertSame('image/jpeg', $uploaded->mime_type);

        $this->actingAs($this->officer, 'sanctum')
            ->get("/api/v1/documents/{$uploaded->id}")
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    }

    /**
     * `serve => true` di disk local mendaftarkan GET+PUT /storage/{path} yang melayani
     * folder ini lewat signed URL — tanpa auth:sanctum, tanpa DocumentPolicy. Route itu
     * lahir dari config, bukan dari routes/api.php, jadi gampang balik lagi tanpa
     * disadari waktu config/filesystems.php disalin ulang dari skeleton Laravel.
     */
    public function test_the_framework_does_not_expose_a_second_door_to_this_disk(): void
    {
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
    }

    public function test_photos_of_a_validated_report_can_no_longer_be_deleted(): void
    {
        IncidentReport::where('uuid', $this->uuid)->update(['report_status' => 'verified']);

        $this->actingAs($this->officer, 'sanctum')
            ->deleteJson("/api/v1/documents/{$this->document->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.report_status.0', 'Laporan sudah disahkan, dokumennya tidak bisa dihapus lagi.');

        $this->assertSame(1, Document::count());
        Storage::disk('local')->assertExists($this->document->path);
    }

    /**
     * Setelah laporan disahkan, cuma surat keringanan yang boleh menyusul — yang lain
     * sudah jadi lampiran Berita Acara yang diteken.
     *
     * `relief_letter` dikecualikan karena memang lahir belakangan: suratnya bermaterai
     * dan disahkan kelurahan. Menutupnya juga berarti hitungan "Dokumen Tertunda" di
     * dashboard MCS tidak akan pernah turun.
     */
    public function test_only_the_relief_letter_may_still_be_uploaded_after_validation(): void
    {
        IncidentReport::where('uuid', $this->uuid)->update(['report_status' => 'verified']);

        $this->actingAs($this->officer, 'sanctum');

        $this->postJson("/api/v1/incident-reports/{$this->uuid}/documents", [
            'file_uuid' => (string) Str::uuid(),
            'document_type' => 'scene_photo',
            'file' => UploadedFile::fake()->image('tkp.jpg'),
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['document_type']]);

        $this->postJson("/api/v1/incident-reports/{$this->uuid}/documents", [
            'file_uuid' => (string) Str::uuid(),
            'document_type' => 'relief_letter',
            'file' => UploadedFile::fake()->image('surat.jpg'),
        ])->assertOk();

        $this->assertSame(2, Document::count());
    }

    // ---- Kompresi foto (6 Okt) — docs/RENCANA-KOMPRESI-FOTO.md -------------------

    /**
     * Server 30 GB tidak boleh bergantung pada FE yang mengompres: foto mentah dari
     * aplikasi versi lama tetap harus tersimpan kecil.
     */
    public function test_foto_unggahan_dikompres_jadi_jpeg_1600px(): void
    {
        $this->actingAs($this->officer, 'sanctum')->postJson("/api/v1/incident-reports/{$this->uuid}/documents", [
            'file_uuid' => $fileUuid = (string) Str::uuid(),
            'document_type' => 'scene_photo',
            'file' => UploadedFile::fake()->image('tkp.png', 3000, 2000),
        ])->assertOk();

        $doc = Document::latest('id')->first();
        $this->assertSame("insiden/{$this->uuid}/{$fileUuid}.jpg", $doc->path);
        $this->assertSame('image/jpeg', $doc->mime_type);
        $this->assertSame(Storage::disk('local')->size($doc->path), $doc->size_bytes);

        [$lebar, $tinggi] = getimagesize(Storage::disk('local')->path($doc->path));
        $this->assertSame([1600, 1067], [$lebar, $tinggi]);
    }

    /** PDF (surat keringanan) bukan foto — disimpan persis seperti yang dikirim. */
    public function test_pdf_disimpan_apa_adanya(): void
    {
        $pdf = UploadedFile::fake()->create('surat.pdf', 120, 'application/pdf');
        $isi = file_get_contents($pdf->getPathname());

        $this->actingAs($this->officer, 'sanctum')->postJson("/api/v1/incident-reports/{$this->uuid}/documents", [
            'file_uuid' => (string) Str::uuid(),
            'document_type' => 'relief_letter',
            'file' => $pdf,
        ])->assertOk();

        $doc = Document::latest('id')->first();
        $this->assertStringEndsWith('.pdf', $doc->path);
        $this->assertSame($isi, Storage::disk('local')->get($doc->path));
    }

    /**
     * Foto yang terlanjur mentah sebelum kompresi dipasang. Yang sudah kecil — termasuk
     * berkas di setUp yang isinya bukan gambar — dilewati, jadi aman diulang.
     */
    public function test_command_mengompres_foto_lama_dan_aman_diulang(): void
    {
        $pathLama = "insiden/{$this->uuid}/".Str::uuid().'.png';
        $mentah = UploadedFile::fake()->image('lama.png', 2400, 1200);
        Storage::disk('local')->put($pathLama, file_get_contents($mentah->getPathname()));
        $lama = Document::create([
            'documentable_type' => 'incident_report',
            'documentable_id' => $this->uuid,
            'document_type' => 'scene_photo',
            'path' => $pathLama,
            'mime_type' => 'image/png',
            'size_bytes' => Storage::disk('local')->size($pathLama),
            'uploaded_by' => $this->officer->id,
            'uploaded_at' => now(),
        ]);

        $this->artisan('documents:compress --dry-run')->assertSuccessful();
        $this->assertSame($pathLama, $lama->fresh()->path);
        Storage::disk('local')->assertExists($pathLama);

        $this->artisan('documents:compress')->assertSuccessful();
        $lama->refresh();
        $this->assertSame(preg_replace('/\.png$/', '.jpg', $pathLama), $lama->path);
        $this->assertSame('image/jpeg', $lama->mime_type);
        Storage::disk('local')->assertMissing($pathLama);
        $this->assertSame([1600, 800], array_slice(getimagesize(Storage::disk('local')->path($lama->path)), 0, 2));

        // Diulang: sudah 1600 px, tidak disentuh lagi. Berkas setUp (bukan gambar) utuh.
        $this->artisan('documents:compress')->assertSuccessful();
        $this->assertSame($lama->path, $lama->fresh()->path);
        $this->assertSame('isi-foto-tkp', Storage::disk('local')->get($this->document->path));
    }
}
