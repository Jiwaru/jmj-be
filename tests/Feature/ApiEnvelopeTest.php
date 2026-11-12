<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kontrak bentuk response: SEMUA /api/v1/* keluar sebagai
 * {success, code, message, data}, sukses maupun gagal.
 *
 * Yang TIDAK diuji di sini karena sudah dijaga test lain:
 * paginasi (IncidentSyncTest) dan unduhan berkas yang harus tetap bytes mentah
 * (DocumentAccessTest::test_the_owner_can_fetch_the_file_back — assertHeader
 * 'image/jpeg' akan jatuh kalau ApiEnvelope ikut membungkus StreamedResponse).
 */
class ApiEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::create([
            'identity_code' => 'JMTO001',
            'name' => 'Petugas Satu',
            'entity' => 'jmto',
            'role' => 'field_officer',
            'is_active' => true,
            'password' => 'rahasia',
        ]);
    }

    public function test_a_success_puts_its_payload_under_data(): void
    {
        $this->actingAs($this->officer(), 'sanctum')->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('code', 200)
            ->assertJsonPath('data.user.identity_code', 'JMTO001')
            // payload lama TIDAK boleh tertinggal di root, kalau tidak FE punya dua jalan
            ->assertJsonMissingPath('user');
    }

    public function test_a_validation_failure_keeps_errors_at_the_top_level(): void
    {
        // 'errors' sengaja tidak dipindah ke dalam 'data': bentuknya persis sama
        // dengan sebelum ada envelope, jadi DomainException(msg, field) dan
        // validasi bawaan Laravel tetap menghasilkan satu bentuk yang sama.
        $this->postJson('/api/v1/login', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422)
            ->assertJsonPath('data', null)
            ->assertJsonStructure(['errors' => ['identity_code']]);
    }

    public function test_an_unauthenticated_request_is_enveloped_too(): void
    {
        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_a_url_that_does_not_exist_is_enveloped_too(): void
    {
        // Ini yang membuktikan $exceptions->respond() kepasang. 404 route-tidak-
        // ketemu lahir SEBELUM middleware grup api jalan, jadi middleware saja
        // meninggalkannya sebagai {message} telanjang.
        $this->getJson('/api/v1/tidak-ada')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 404);
    }
}
