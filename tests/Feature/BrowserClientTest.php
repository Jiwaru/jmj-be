<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Klien resminya PWA di browser, bukan APK. Dua pertahanan di bawah ini gratis pada
 * klien native dan wajib pada browser — dan dua-duanya lahir dari config/middleware,
 * bukan dari controller, jadi tidak ada satu pun test lain yang akan menangkap kalau
 * salah satunya hilang.
 */
class BrowserClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_foreign_origin_is_not_granted_cors_access(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://situs-jahat.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        // stack-cors tetap MENGIRIM header ini, tapi isinya origin yang kita izinkan,
        // bukan origin penyerang — dan browser memblokir karena keduanya tidak sama.
        // Yang berbahaya cuma dua nilai: '*' (default framework sebelum config/cors.php
        // ada) dan pantulan origin penyerang apa adanya.
        $allowed = $response->headers->get('Access-Control-Allow-Origin');

        $this->assertNotSame('*', $allowed);
        $this->assertNotSame('https://situs-jahat.example', $allowed);
    }

    /**
     * Respons laporan memuat NIK, alamat, dan nomor SIM pihak ketiga. Default Symfony
     * 'no-cache, private' tetap MENYIMPAN respons itu ke disk cache browser — cuma
     * mewajibkan revalidasi sebelum dipakai lagi. Di tablet yang dipakai bergantian,
     * data itu bertahan setelah petugasnya logout.
     */
    public function test_responses_carrying_third_party_pii_are_never_stored_by_the_browser(): void
    {
        $officer = User::create([
            'identity_code' => 'JMTO001', 'name' => 'Petugas', 'entity' => 'jmto',
            'role' => 'field_officer', 'is_active' => true,
            'password' => 'rahasia',
        ]);
        $officer2 = User::create([
            'identity_code' => 'JMTO002', 'name' => 'Petugas Kedua', 'entity' => 'jmto',
            'role' => 'field_officer', 'is_active' => true,
            'password' => 'rahasia',
        ]);
        $uuid = (string) Str::uuid();

        IncidentReport::create([
            'uuid' => $uuid, 'user_id' => $officer->id, 'officer_2_id' => $officer2->id,
            'date_time' => '2026-08-21 14:20:00', 'causer' => 'Pecah Ban', 'analyze' => 'Rusak Berat',
            'report_status' => 'pending_verification', 'sync_status' => 'sent',
        ]);

        $response = $this->actingAs($officer, 'sanctum')
            ->getJson("/api/v1/incident-reports/{$uuid}")
            ->assertOk();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
