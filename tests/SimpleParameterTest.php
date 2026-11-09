<?php
declare(strict_types=1);
namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\QuerySerializer;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Tests\Fixtures\User;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class SimplePathParams extends DTO {
    public const array REQUIRED_FIELDS = ['ids'];
    public const array OPERATION_FIELDS = ['path' => ['ids' => 'ids'], 'query' => [], 'header' => []];
    protected const array VALIDATION = ['ids' => ['minItems' => 1, 'maxItems' => 4, 'items' => ['type' => 'string', 'rules' => ['minLength' => 1]]]];
    public array $ids;
}
class SimplePayload extends DTO {
    public const bool OPERATION_BODY = true;
    public const array OPERATION_FIELDS = ['path' => [], 'query' => ['body' => 'queryBody', 'q' => 'q'], 'header' => ['X-Ids' => 'codes']];
    protected const array VALIDATION = ['codes' => ['items' => ['type' => 'string', 'rules' => []]]];
    public string $queryBody;
    public array $q;
    public array $codes;
    public User $body;
}
class SimpleParameterClient extends ApiClient {
    public function submit(DTO $path, DTO $params): void {
        $this->executeArguments(['method' => 'POST', 'path' => '/items/{ids}', 'success' => [204],
            'pathSerialization' => ['ids' => 'csv'], 'headerSerialization' => ['X-Ids' => 'csv'], 'querySerialization' => ['q' => 'multi']],
            identifier: $path, payload: $params);
    }
    public function raw(array $path, array $headers = [], array $metadata = []): void {
        $this->executeOperation(['method' => 'GET', 'path' => '/items/{ids}', 'success' => [204]] + $metadata,
            pathParameters: $path, headerParameters: $headers);
    }
}
final class SimpleParameterTest extends TestCase {
    public function testNativeItemEncodingAndRawHeaderRulesAreDifferent(): void {
        self::assertSame('a%2Cb,x%20y,c%2Fd,%252F,%C3%B1,true,false,1.5',
            QuerySerializer::simpleList(['a,b', 'x y', 'c/d', '%2F', 'ñ', true, false, 1.5], 'ids'));
        self::assertSame('alpha,x y,%2F,false,2', QuerySerializer::simpleList(['alpha', 'x y', '%2F', false, 2], 'X-Ids', true));
        self::assertSame('', QuerySerializer::simpleList([], 'ids'));
        self::assertSame(',x', QuerySerializer::simpleList(['', 'x'], 'ids'));
    }
    public function testAmbiguousAndInvalidItemsFailInsteadOfInventingHeaderEscapes(): void {
        foreach (['a,b', "a\r\nX: value", "\0", ' a', "a\t", "\x7f"] as $value) {
            try { QuerySerializer::simpleList([$value], 'X-Ids', true); self::fail('Invalid header accepted.'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
        foreach ([[null], [new \stdClass()], [[1]], ['x' => 1], [INF], [NAN], 'text'] as $value) {
            try { QuerySerializer::simpleList($value, 'ids'); self::fail('Invalid list accepted.'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
    }
    public function testInheritedExecutorKeepsPathQueryHeadersAndBodySeparateInBothTransports(): void {
        $expected = 'https://example.test/items/a%2Cb,c%2Fd,%252F,%C3%B1?body=query%20body&q=2&q=false';
        $http = new class($expected) implements ClientInterface {
            public function __construct(private string $expected) {}
            public function sendRequest(RequestInterface $request): ResponseInterface {
                TestCase::assertSame($this->expected, (string) $request->getUri());
                TestCase::assertSame('alpha,beta', $request->getHeaderLine('X-Ids'));
                TestCase::assertSame('{"name":"Ada"}', (string) $request->getBody());
                return new Response(204);
            }
        };
        foreach ([false, true] as $psr) {
            $transport = new FakeTransport(new HttpResponse(204, ''));
            $client = SimpleParameterClient::connect('https://example.test', httpClient: $http, transport: $psr ? null : $transport);
            $path = new SimplePathParams((object) ['ids' => ['a,b', 'c/d', '%2F', 'ñ']]);
            $params = new SimplePayload((object) ['queryBody' => 'query body', 'q' => [2, false], 'codes' => ['alpha', 'beta'], 'body' => (object) ['name' => 'Ada']]);
            $client->submit($path, $params);
            if (!$psr) {
                self::assertSame($expected, $transport->sent[0]['url']);
                self::assertSame('alpha,beta', $transport->sent[0]['headers']['X-Ids']);
                self::assertSame('{"name":"Ada"}', $transport->sent[0]['body']);
            }
            $params->codes = ['a,b'];
            try { $client->submit($path, $params); self::fail('Invalid header mutation reached transport.'); }
            catch (\UnexpectedValueException) { self::assertCount($psr ? 0 : 1, $transport->sent); }
            $params->codes = ['alpha']; $path->ids = [];
            try { $client->submit($path, $params); self::fail('Invalid path mutation reached transport.'); }
            catch (\UnexpectedValueException) { self::assertCount($psr ? 0 : 1, $transport->sent); }
        }
    }
    public function testNumericWireNamesRetainTheirProtocolNames(): void {
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface {
                TestCase::assertSame('https://example.test/numeric/a%2Cb,c%2Fd', (string) $request->getUri());
                TestCase::assertSame('alpha,beta', $request->getHeaderLine('0'));
                return new Response(204);
            }
        };
        foreach ([false, true] as $psr) {
            $transport = new FakeTransport(new HttpResponse(204, ''));
            $client = new class('https://example.test', httpClient: $http, transport: $psr ? null : $transport) extends ApiClient {
                public function numeric(): void {
                    $this->executeOperation(['method' => 'GET', 'path' => '/numeric/{0}', 'success' => [204],
                        'pathSerialization' => ['0' => 'csv'], 'headerSerialization' => ['0' => 'csv']],
                        pathParameters: ['0' => ['a,b', 'c/d']], headerParameters: ['0' => ['alpha', 'beta']]);
                }
            };
            $client->numeric();
            if (!$psr) {
                self::assertSame('https://example.test/numeric/a%2Cb,c%2Fd', $transport->sent[0]['url']);
                self::assertSame('alpha,beta', $transport->sent[0]['headers'][0]);
            }
        }
    }
    public function testMissingNullUndeclaredAndUnsupportedFormatsFailBeforeTransport(): void {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $client = SimpleParameterClient::connect('https://example.test', transport: $transport);
        foreach ([[[], [], []], [['ids' => null], [], []], [['ids' => [1, 2]], [], []],
            [['ids' => 'one'], ['X-Ids' => [1, 2]], []], [['ids' => [1, 2]], [], ['pathSerialization' => ['ids' => 'multi']]]] as [$path, $headers, $metadata]) {
            try { $client->raw($path, $headers, $metadata); self::fail('Invalid metadata/input accepted.'); }
            catch (\UnexpectedValueException|\InvalidArgumentException) { self::assertSame([], $transport->sent); }
        }
        $client->raw(['ids' => []], ['X-Ids' => []], ['pathSerialization' => ['ids' => 'csv'], 'headerSerialization' => ['X-Ids' => 'csv']]);
        self::assertSame('https://example.test/items/', $transport->sent[0]['url']);
        self::assertSame('', $transport->sent[0]['headers']['X-Ids']);
    }
}
