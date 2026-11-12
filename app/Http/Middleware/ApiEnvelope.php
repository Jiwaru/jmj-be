<?php

namespace App\Http\Middleware;

use Closure;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response as OpenApiResponse;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NullType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Satu bentuk response untuk seluruh /api/v1/* — sukses maupun gagal:
 *
 *   {success, code, message, data}  (+ meta kalau berpaginasi, + errors kalau validasi gagal)
 *
 * Dipasang di luar controller dengan sengaja. Controller tetap mengembalikan
 * array polos seperti aturan KONVENSI §8; kalau envelope dibentuk manual di
 * controller, cukup satu endpoint yang lupa untuk melahirkan bentuk kedua —
 * dan itu persis yang sedang dihilangkan.
 *
 * Dipanggil dari dua tempat (lihat bootstrap/app.php):
 *   1. sebagai middleware grup `api` — semua response yang lewat routing
 *   2. dari $exceptions->respond()  — 404 URL salah ketik & 405 method salah,
 *      yang lahir SEBELUM route ketemu dan tidak pernah melewati middleware
 * Makanya wrap() harus idempoten.
 */
class ApiEnvelope
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Bukan JsonResponse = tidak disentuh. Itu satu-satunya penjaga yang
        // dibutuhkan unduhan berkas: DocumentController::show() mengembalikan
        // StreamedResponse, lengkap dengan header keamanannya.
        return $response instanceof JsonResponse ? self::wrap($response) : $response;
    }

    public static function wrap(JsonResponse $response): JsonResponse
    {
        $payload = (array) $response->getData(true);

        if (isset($payload['success'], $payload['code'])) {
            return $response;
        }

        $status = $response->getStatusCode();
        $ok = $status < 400;

        $message = $payload['message'] ?? ($ok ? 'Berhasil.' : 'Terjadi kesalahan.');
        unset($payload['message']);

        // errors tetap di level atas, bentuknya persis seperti sebelum ada envelope.
        // exception/file/line/trace ikut supaya APP_DEBUG=true tetap berguna di dev.
        $extra = Arr::only($payload, ['errors', 'exception', 'file', 'line', 'trace']);

        // Paginator Laravel datang rata di root: barisnya di 'data', sisanya halaman.
        $meta = null;
        $data = $ok ? ($payload ?: null) : null;

        if ($ok && isset($payload['data'], $payload['current_page'])) {
            $meta = Arr::only($payload, ['current_page', 'per_page', 'total', 'last_page', 'from', 'to']);
            $data = $payload['data'];   // halaman kosong tetap [], bukan null
        }

        $envelope = [
            'success' => $ok,
            'code' => $status,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== null) {
            $envelope['meta'] = $meta;
        }

        return $response->setData($envelope + $extra);
    }

    /**
     * Bentuk yang sama, diceritakan ke Scramble.
     *
     * Scramble membaca array polos yang dikembalikan controller dan berhenti di
     * situ — envelope dipasang middleware, jauh sesudah controller, jadi generator
     * tidak punya cara menebaknya. Tanpa ini `/docs/api` menjanjikan `{report}`
     * padahal yang keluar `{success, code, message, data: {report}}`, dan FE yang
     * mempercayai OpenAPI-nya membangun parser yang salah untuk SEPULUH endpoint.
     *
     * Ditaruh di kelas ini, bukan di provider, karena aturannya sama persis dengan
     * wrap() di atas: message diangkat, paginator dipecah jadi data + meta, errors
     * tetap di level atas. Dua salinan aturan yang sama di dua berkas akan berbeda
     * pada perubahan berikutnya — dan yang melenceng justru dokumennya, bagian
     * yang tidak punya test.
     *
     * Dipanggil sekali dari AppServiceProvider::boot().
     */
    public static function describe(Operation $operation): void
    {
        foreach ($operation->responses ?? [] as $response) {
            // Response 4xx lahir sebagai komponen bersama dan dirujuk banyak
            // operasi. resolve() memberi objek yang SAMA untuk semuanya, jadi
            // yang ini dibungkus sekali dan sisa rujukannya kena penjaga
            // idempoten di bawah — persis alasan wrap() juga harus idempoten.
            $response = $response instanceof Reference ? $response->resolve() : $response;

            if (! $response instanceof OpenApiResponse) {
                continue;
            }

            $schema = $response->content['application/json'] ?? null;

            // Bukan JSON = tidak disentuh, sama seperti handle(): unduhan berkas
            // keluar sebagai StreamedResponse dan tidak pernah dibungkus.
            if (! $schema instanceof Schema) {
                continue;
            }

            $payload = $schema->type;

            if ($payload instanceof ObjectType && $payload->hasProperty('success') && $payload->hasProperty('code')) {
                continue;
            }

            $status = is_numeric($response->code) ? (int) $response->code : 200;
            $ok = $status < 400;

            $meta = null;
            $extra = [];
            $data = $ok ? $payload : new NullType;

            // Default sama dengan wrap(); ditimpa message asli kalau payload punya.
            $message = (new StringType)->example($ok ? 'Berhasil.' : 'Terjadi kesalahan.');

            if ($payload instanceof ObjectType) {
                // message diangkat jadi message envelope, bukan bagian data —
                // termasuk contoh nilainya, supaya 401 tetap terbaca
                // "Unauthenticated." dan bukan pesan generik.
                if ($payload->hasProperty('message')) {
                    $message = $payload->getProperty('message') ?? $message;
                }

                unset($payload->properties['message']);

                foreach (['errors', 'exception', 'file', 'line', 'trace'] as $key) {
                    if ($payload->hasProperty($key)) {
                        $extra[$key] = $payload->getProperty($key);
                        unset($payload->properties[$key]);
                    }
                }

                $payload->setRequired(array_values(array_intersect(
                    $payload->required,
                    array_keys($payload->properties),
                )));

                if ($ok && $payload->hasProperty('data') && $payload->hasProperty('current_page')) {
                    // Paginator datang rata di root: barisnya di 'data', sisanya halaman.
                    $data = $payload->getProperty('data');
                    $meta = new ObjectType;

                    foreach (['current_page', 'per_page', 'total', 'last_page', 'from', 'to'] as $key) {
                        if ($payload->hasProperty($key)) {
                            $meta->addProperty($key, $payload->getProperty($key));
                        }
                    }
                } elseif ($ok && $payload->properties === []) {
                    // Yang isinya cuma message — logout. wrap() menulis null, bukan {}.
                    $data = new NullType;
                }
            }

            $envelope = (new ObjectType)
                ->addProperty('success', (new BooleanType)->example($ok))
                ->addProperty('code', (new IntegerType)->example($status))
                ->addProperty('message', $message)
                ->addProperty('data', $data);

            if ($meta !== null) {
                $envelope->addProperty('meta', $meta);
            }

            foreach ($extra as $key => $type) {
                $envelope->addProperty($key, $type);
            }

            $envelope->setRequired(['success', 'code', 'message', 'data']);

            $response->setContent('application/json', Schema::fromType($envelope));
        }
    }
}
