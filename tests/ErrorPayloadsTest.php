<?php
declare(strict_types=1);
namespace Dmorenof\SdkRuntime\Tests;
use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\ApiException;
use Dmorenof\SdkRuntime\Execution\ResponseDecoder;
use Dmorenof\SdkRuntime\Operation;
use Dmorenof\SdkRuntime\Tests\Fixtures\DirectionalUser;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Tests\Fixtures\Role;
use Dmorenof\SdkRuntime\Tests\Fixtures\Scores;
use Dmorenof\SdkRuntime\Tests\Fixtures\Tags;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

final class ErrorPayloadsTest extends TestCase {
    /** @param array<string,mixed>|null $errorResponse */
    private static function failure(HttpResponse $response, string $label, ?string $errorType = null, ?array $errorResponse = null): ApiException {
        $spec = Operation::fromArray(['method' => 'GET', 'path' => '/', 'label' => $label]
            + ($errorType === null ? [] : ['errors' => [$response->status => $errorType]])
            + ($errorResponse === null ? [] : ['errorResponses' => [$response->status => $errorResponse]]));
        return ResponseDecoder::failure($spec, $response, $response->header('Content-Type'));
    }

    public function testNativeErrorPayloadsKeepHttpResponseAndLegacyDtoProperty(): void {
        foreach ([['"issue"', 'string', 'issue'], ['1.0', 'int', 1], ['false', 'bool', false], ['null', 'null', null], ['[1,2]', 'array', [1, 2]], ['"admin"', Role::class, Role::Admin]] as [$body, $type, $expected]) {
            $response = new HttpResponse(422, $body, ['X-Trace' => 'trace']);
            $error = self::failure($response, 'POST /items', null, ['type' => $type, 'schemaNullable' => false]);
            self::assertSame($expected, $error->payload); self::assertNull($error->error); self::assertNull($error->getPrevious());
            self::assertSame($response, $error->response); self::assertSame(422, $error->getCode());
        }
        $response = new HttpResponse(400, '{"id":7,"name":"Alice"}');
        $error = self::failure($response, 'GET /user', DirectionalUser::class, ['type' => DirectionalUser::class, 'schemaNullable' => false]);
        self::assertInstanceOf(DirectionalUser::class, $error->payload); self::assertSame($error->error, $error->payload); self::assertNull($error->getPrevious());
        $legacy = self::failure($response, 'GET /user', DirectionalUser::class); self::assertSame($legacy->error, $legacy->payload);
    }
    public function testMapsCollectionsAndRawErrorsReuseExistingHydration(): void {
        $map = self::failure(new HttpResponse(400, '{"one":1}'), 'GET /map', null, ['type' => Scores::class, 'schemaNullable' => false]);
        self::assertInstanceOf(Scores::class, $map->payload); self::assertSame(1, $map->payload['one']); self::assertNull($map->error);
        $list = self::failure(new HttpResponse(400, '["a","b"]'), 'GET /list', null, ['type' => Tags::class, 'schemaNullable' => false]);
        self::assertInstanceOf(Tags::class, $list->payload); self::assertSame(['a', 'b'], iterator_to_array($list->payload));
        foreach (['text/plain' => 'not JSON', 'application/octet-stream' => "\0\xffbytes"] as $media => $bytes) {
            $error = self::failure(new HttpResponse(503, $bytes), 'GET /raw', null, ['type' => 'string', 'schemaNullable' => false, 'mediaType' => $media]);
            self::assertSame($bytes, $error->payload); self::assertNull($error->getPrevious());
        }
    }
    public function testInvalidPayloadNeverHidesTheHttpFailure(): void {
        foreach ([['bad JSON', ['type' => 'int', 'schemaNullable' => false], \JsonException::class], ['{"a":1}', ['type' => 'int', 'schemaNullable' => false], \UnexpectedValueException::class]] as [$body, $descriptor, $cause]) {
            $response = new HttpResponse(400, $body); $error = self::failure($response, 'GET /bad', null, $descriptor);
            self::assertSame($response, $error->response); self::assertNull($error->payload); self::assertNull($error->error); self::assertInstanceOf($cause, $error->getPrevious());
        }
        $error = self::failure(new HttpResponse(400, 'not JSON'), 'GET /empty', null, ['type' => null, 'schemaNullable' => false]);
        self::assertNull($error->payload); self::assertNull($error->getPrevious());
    }
    public function testExactRangeDefaultAndBodylessErrorsFollowOperationSelection(): void {
        foreach ([[400, 'invalid ignored body', null], [422, '7', 7], [503, 'false', false]] as [$status, $body, $expected]) {
            $transport = new FakeTransport(new HttpResponse($status, $body));
            $client = new class(\Dmorenof\SdkRuntime\Tests\Support\Cfg::make('https://example.test', transport: $transport)) extends ApiClient {
                public function get(): mixed { return $this->executeOperation(['method' => 'GET', 'path' => '/errors', 'errorResponses' => [
                    400 => ['type' => null, 'schemaNullable' => false], '4XX' => ['type' => 'int', 'schemaNullable' => false], 'default' => ['type' => 'bool', 'schemaNullable' => false]]]); }
            };
            try { $client->get(); self::fail('HTTP error became success.'); }
            catch (ApiException $error) { self::assertSame($expected, $error->payload); self::assertSame($status, $error->getCode()); self::assertNull($error->getPrevious()); }
        }
    }
}
