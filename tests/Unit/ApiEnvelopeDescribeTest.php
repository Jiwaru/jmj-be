<?php

namespace Tests\Unit;

use App\Http\Middleware\ApiEnvelope;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response as OpenApiResponse;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Illuminate\Http\JsonResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ApiEnvelope punya dua sisi yang menceritakan bentuk yang sama:
 * wrap() ke klien sungguhan, describe() ke Scramble.
 *
 * Yang diuji di sini cuma satu hal: KEDUANYA MENYEBUT KUNCI YANG SAMA. Kalau
 * salah satu diubah tanpa yang lain, /docs/api mulai menjanjikan bentuk yang
 * tidak pernah keluar — dan tidak ada yang tahu sampai FE membangun parser
 * yang salah, karena dokumen tidak pernah gagal berisik seperti kode.
 */
class ApiEnvelopeDescribeTest extends TestCase
{
    public static function payloads(): array
    {
        return [
            'laporan biasa' => [200, ['report' => ['uuid' => 'a', 'km' => 1]]],
            'logout, isinya cuma message' => [200, ['message' => 'Berhasil keluar.']],
            'daftar berpaginasi' => [200, [
                'data' => [], 'current_page' => 1, 'per_page' => 20,
                'total' => 0, 'last_page' => 1, 'from' => null, 'to' => null,
            ]],
            'validasi gagal' => [422, [
                'message' => 'Data tidak valid.',
                'errors' => ['causer' => ['Penyebab wajib diisi.']],
            ]],
            'tidak berhak' => [403, ['message' => 'Tidak berhak.']],
        ];
    }

    #[DataProvider('payloads')]
    public function test_the_documented_shape_matches_what_the_middleware_really_writes(int $status, array $payload): void
    {
        $real = ApiEnvelope::wrap(new JsonResponse($payload, $status))->getData(true);

        $operation = new Operation('get');
        $operation->addResponse(
            (new OpenApiResponse($status))->setContent('application/json', Schema::fromType(self::toType($payload)))
        );

        ApiEnvelope::describe($operation);

        $documented = $operation->responses[0]->content['application/json']->type;

        $this->assertSame(
            array_keys($real),
            array_keys($documented->properties),
            'Kunci di dokumen dan kunci yang benar-benar ditulis middleware sudah berbeda.',
        );

        // `data: null` di wrap() harus jadi type null di dokumen, bukan objek kosong.
        $this->assertSame(
            $real['data'] === null,
            $documented->getProperty('data')->type === 'null',
            'Nullnya data tidak sama antara response sungguhan dan dokumen.',
        );
    }

    /** Dipanggil dua kali tidak boleh melahirkan envelope di dalam envelope. */
    public function test_describing_twice_wraps_once(): void
    {
        $operation = new Operation('get');
        $operation->addResponse(
            (new OpenApiResponse(200))->setContent('application/json', Schema::fromType(self::toType(['report' => ['uuid' => 'a']])))
        );

        ApiEnvelope::describe($operation);
        ApiEnvelope::describe($operation);

        $data = $operation->responses[0]->content['application/json']->type->getProperty('data');

        $this->assertTrue($data->hasProperty('report'));
        $this->assertFalse($data->hasProperty('success'));
    }

    /** Unduhan berkas bukan JSON — wrap() melewatinya, describe() juga harus. */
    public function test_a_non_json_response_is_left_alone(): void
    {
        $operation = new Operation('get');
        $operation->addResponse(
            (new OpenApiResponse(200))->setContent('application/octet-stream', Schema::fromType(new StringType))
        );

        ApiEnvelope::describe($operation);

        $this->assertSame([], array_keys($operation->responses[0]->content['application/json'] ?? []));
        $this->assertArrayHasKey('application/octet-stream', $operation->responses[0]->content);
    }

    private static function toType(array $payload): ObjectType
    {
        $object = new ObjectType;

        foreach ($payload as $key => $value) {
            $object->addProperty($key, match (true) {
                is_int($value) => new IntegerType,
                is_array($value) && array_is_list($value) => new ArrayType,
                is_array($value) => self::toType($value),
                default => new StringType,
            });
        }

        return $object->setRequired(array_keys($payload));
    }
}
