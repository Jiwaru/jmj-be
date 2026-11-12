<?php

use App\Exceptions\DomainException;
use App\Http\Middleware\ApiEnvelope;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // `active` dipasang berdampingan dengan `auth:sanctum` di routes/api.php, BUKAN
        // di dalam `role` — kalau ikut role, tiga route yang tidak ber-role
        // (me, logout, sync/bootstrap) tetap menerima token akun yang sudah dimatikan.
        $middleware->alias([
            'role' => EnsureRole::class,
            'active' => EnsureActiveAccount::class,
        ]);

        // Bentuk response seragam {success, code, message, data}. prepend, bukan
        // append: throttle:5,1 di login melempar 429 dari DALAM grup api, jadi
        // middleware yang dipasang paling depan yang ikut melihat response-nya
        // waktu naik lagi. Kalau di-append, 429 lolos tanpa envelope.
        $middleware->api(prepend: [ApiEnvelope::class]);

        // BELUM ada trustProxies() — dan itu disengaja, tapi ada batas waktunya.
        // Sekarang FrankenPHP dihubungi langsung, jadi $request->ip() sudah benar.
        // Begitu ada nginx di depannya (rencana NAS), ip() akan mengembalikan IP
        // nginx untuk SEMUA orang: throttle:5,1 di login berubah dari "5 percobaan
        // per menit per orang" jadi "5 per menit untuk seluruh kantor" — satu orang
        // salah password lima kali, semua terkunci. Waktu itu tiba, tambahkan
        // $middleware->trustProxies(at: '<IP/CIDR nginx>') dengan alamat betulan.
        // JANGAN '*': selama port aplikasi ini masih bisa dihubungi langsung,
        // mempercayai semua proxy berarti siapa pun boleh memalsukan
        // X-Forwarded-For dan lolos dari rate limit yang sama.

        // App ini tidak punya halaman login — identitasnya bearer token. Default
        // Laravel mengalihkan tamu ke route bernama 'login', dan karena route itu
        // tidak ada, request tanpa token berakhir HTTP 500 "Route [login] not
        // defined" alih-alih 401. Mengembalikan null berarti tidak ada pengalihan,
        // jadi AuthenticationException dirender apa adanya.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Tanpa ini, request ke /api/* yang lupa kirim `Accept: application/json`
        // tidak dapat 401 JSON: Laravel mencoba mengalihkan ke route bernama
        // 'login' yang tidak ada di app ini, dan hasilnya HTTP 500 halaman HTML.
        // Menaruh syaratnya di server, bukan mengandalkan tiap klien ingat header.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Satu-satunya tempat aturan bisnis diterjemahkan jadi HTTP. Bentuknya
        // sengaja meniru error validasi Laravel supaya frontend cuma perlu
        // menangani satu bentuk 422, bukan dua. ApiEnvelope membungkusnya
        // setelah ini — 'errors' tetap di level atas, jadi baris ini tidak
        // perlu tahu soal envelope sama sekali.
        $exceptions->render(fn (DomainException $e) => response()->json(
            $e->field
                ? ['message' => $e->getMessage(), 'errors' => [$e->field => [$e->getMessage()]]]
                : ['message' => $e->getMessage()],
            422,
        ));

        // Jaring kedua untuk envelope. Exception yang lahir SEBELUM route ketemu
        // — URL salah ketik (404), method salah (405) — tidak pernah melewati
        // middleware grup api, jadi middleware saja meninggalkan dua lubang di
        // kontrak. wrap() idempoten, jadi aman kalau dua-duanya kena response
        // yang sama.
        $exceptions->respond(fn (Response $response, Throwable $e, Request $request) => $response instanceof JsonResponse && $request->is('api/*')
            ? ApiEnvelope::wrap($response)
            : $response);
    })->create();
