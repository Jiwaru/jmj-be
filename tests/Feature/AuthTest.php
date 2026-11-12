<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        return User::create($attrs + [
            'identity_code' => 'JMTO001',
            'name' => 'Petugas Satu',
            'entity' => 'jmto',
            'role' => 'field_officer',
            'password' => 'rahasia',
        ]);
    }

    private function login(array $body = []): TestResponse
    {
        return $this->postJson('/api/v1/login', $body + [
            'identity_code' => 'JMTO001',
            'password' => 'rahasia',
        ]);
    }

    public function test_login_returns_token_plus_role_and_entity(): void
    {
        $this->user();

        $response = $this->login()->assertOk()
            ->assertJsonPath('data.user.role', 'field_officer')
            ->assertJsonPath('data.user.entity', 'jmto');

        $this->assertArrayNotHasKey('password', $response->json('data.user'));
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_rejects_wrong_password(): void
    {
        $this->user();

        $this->login(['password' => 'salah'])->assertStatus(422);
    }

    public function test_login_rejects_inactive_user(): void
    {
        $this->user(['is_active' => false]);

        $this->login()->assertStatus(422);
    }

    /**
     * Token yang terlanjur terbit HARUS mati begitu akunnya dinonaktifkan.
     *
     * Ini yang membuat pilihan Sanctum berarti. Keputusan 28 Ags menolak JWT dengan
     * kalimat yang persis menggambarkan kegagalan ini: "JWT tidak bisa dicabut — HP
     * petugas hilang di jalan tol, atau `is_active` di-flip `false`, tokennya tetap hidup
     * sampai expiry." Kalau test ini merah, Sanctum berperilaku persis seperti JWT dan
     * seluruh alasan memilihnya batal.
     *
     * Diuji di route TANPA penjaga role juga (`me`) — kalau penjaganya ikut menempel di
     * middleware `role`, tiga route (me, logout, sync/bootstrap) bocor tanpa ada yang tahu.
     */
    public function test_token_mati_begitu_akunnya_dinonaktifkan(): void
    {
        $user = $this->user();
        $token = $this->login()->assertOk()->json('data.token');
        $bearer = ['Authorization' => "Bearer {$token}"];

        $this->getJson('/api/v1/me', $bearer)->assertOk();

        $user->update(['is_active' => false]);

        // WAJIB, dan bukan kerapian. Beberapa request dalam SATU test berbagi container
        // aplikasi yang sama, dan guard `auth` menyimpan user yang sudah di-resolve.
        // Tanpa baris ini request berikutnya memakai salinan basi yang masih aktif, test
        // ini hijau-palsu, dan penjaganya tidak pernah benar-benar diuji. Di produksi tiap
        // request punya container sendiri, jadi masalahnya cuma ada di test.
        $this->app['auth']->forgetGuards();

        foreach (['/api/v1/me', '/api/v1/sync/bootstrap', '/api/v1/incident-reports'] as $url) {
            $this->getJson($url, $bearer)
                ->assertStatus(401)
                ->assertJsonPath('message', fn ($p) => str_contains($p, 'dinonaktifkan'));
        }

        // Dan hidup lagi kalau diaktifkan kembali — penjaganya membaca keadaan sekarang,
        // bukan mencabut tokennya permanen.
        $user->update(['is_active' => true]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', $bearer)->assertOk();
    }

    public function test_me_needs_a_token_and_logout_revokes_it(): void
    {
        $this->user();
        $token = $this->login()->json('data.token');

        $this->getJson('/api/v1/me')->assertStatus(401);
        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.user.identity_code', 'JMTO001');

        $this->withToken($token)->postJson('/api/v1/logout')->assertOk()->assertJsonPath('success', true);

        // Guard-nya di-cache antar request dalam satu proses test; di produksi tiap
        // request app-nya baru. Tanpa ini token yang sudah dihapus masih lolos.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
    }
}
