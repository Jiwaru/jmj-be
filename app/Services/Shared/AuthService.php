<?php

namespace App\Services\Shared;

use App\Exceptions\DomainException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    /** Umur token. Diperpanjang tiap sync sukses — petugas lapangan tidak login tiap hari. */
    private const TOKEN_TTL_DAYS = 30;

    /**
     * @throws DomainException kredensial salah atau akun nonaktif
     */
    public function login(string $identityCode, string $password): array
    {
        $user = User::where('identity_code', $identityCode)->first();

        // Hash::check(), bukan Auth::attempt() — attempt() menulis ke session guard,
        // padahal SESSION_DRIVER=file dan identitas dibaca dari personal_access_tokens.
        if (! $user || ! Hash::check($password, $user->password)) {
            throw new DomainException('Kode identitas atau kata sandi salah.', 'identity_code');
        }

        if (! $user->is_active) {
            throw new DomainException('Akun ini sudah dinonaktifkan.', 'identity_code');
        }

        return [
            'token' => $user->createToken('api', ['*'], now()->addDays(self::TOKEN_TTL_DAYS))->plainTextToken,
            'user' => $user->profile(),
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}
