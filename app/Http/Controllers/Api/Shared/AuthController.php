<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Services\Shared\AuthService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(LoginRequest $request): array
    {
        return $this->auth->login(
            $request->validated('identity_code'),
            $request->validated('password'),
        );
    }

    public function me(Request $request): array
    {
        return ['user' => $request->user()->profile()];
    }

    public function logout(Request $request): array
    {
        $this->auth->logout($request->user());

        return ['message' => 'Berhasil keluar.'];
    }
}
