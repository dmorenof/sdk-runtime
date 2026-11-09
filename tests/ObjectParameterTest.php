<?php
declare(strict_types=1);
namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\Map;
use Dmorenof\SdkRuntime\QuerySerializer;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Tests\Fixtures\User;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class FlatParameterDto extends DTO {
    public const array REQUIRED_FIELDS = ['code'];
    protected const array READ_ONLY_FIELDS = ['server'];
    protected const array VALIDATION = ['code' => ['minLength' => 1]];
    public string $code;
    public bool $active;
    public string $server;
}
class FlatParameterPath extends DTO {
    public const array REQUIRED_FIELDS = ['selector'];
    public const array OPERATION_FIELDS = ['path' => ['selector' => 'selector'], 'query' => [], 'header' => []];
    public FlatParameterDto $selector;
}
class FlatParameterPayload extends DTO {
    public const bool OPERATION_BODY = true;
    public const array OPERATION_FIELDS = ['path' => [], 'query' => ['filter' => 'filter', 'body' => 'queryBody'], 'header' => ['X-Filter' => 'filter']];
    public FlatParameterDto $filter;
    public string $queryBody;
    public User $body;
}
class FlatParameterClient extends ApiClient {
    public function submit(DTO $path, DTO $payload): void {
        $this->executeArguments(['method' => 'POST', 'path' => '/items/{selector}', 'success' => [204],
            'pathSerialization' => ['selector' => 'object'], 'headerSerialization' => ['X-Filter' => 'object-explode'],
            'querySerialization' => ['filter' => 'form-object']], identifier: $path, payload: $payload);
    }
}
final class ObjectParameterTest extends TestCase {
    public function testSimpleObjectsEncodeKeysAndValuesWithExplicitExplode(): void {
        $object = (object) ['co,de' => 'a,b/ñ', 'active' => false, 'count' => 0, 'percent' => '%2F'];
        self::assertSame('co%2Cde,a%2Cb%2F%C3%B1,active,false,count,0,percent,%252F', QuerySerializer::simple($object, 'filter', 'object'));
        self::assertSame('co%2Cde=a%2Cb%2F%C3%B1,active=false,count=0,percent=%252F', QuerySerializer::simple($object, 'filter', 'object-explode'));
        self::assertSame('code=safe,active=false', QuerySerializer::simple((object) ['code' => 'safe', 'active' => false], 'X-Filter', 'object-explode', true));
        self::assertSame('code,safe,active,false', QuerySerializer::simple((object) ['code' => 'safe', 'active' => false], 'X-Filter', 'object', true));
        self::assertSame('x=a=b', QuerySerializer::simple((object) ['x' => 'a=b'], 'X-Filter', 'object-explode', true));
        self::assertSame('0,value', QuerySerializer::simple((object) ['0' => 'value'], '0', 'object'));
        self::assertSame('', QuerySerializer::simple(new \stdClass(), 'filter', 'object'));
        self::assertSame('x%5By%5D,value', QuerySerializer::simple((object) ['x[y]' => 'value'], 'filter', 'object'));
    }
    public function testQueryFormObjectsReuseTheChosenNativeEncoding(): void {
        $object = (object) ['city' => 'x y,ñ', 'active' => false];
        self::assertSame('filter=city,x%20y%2C%C3%B1,active,false', QuerySerializer::encode(['filter' => $object], ['filter' => 'form-object']));
        self::assertSame('city=x%20y%2C%C3%B1&active=false', QuerySerializer::encode(['filter' => $object], ['filter' => 'form-object-explode']));
        self::assertSame('filter=city,x+y%2C%C3%B1,active,false', QuerySerializer::encode(['filter' => $object], ['filter' => 'form-object'], PHP_QUERY_RFC1738));
        self::assertSame('', QuerySerializer::encode(['filter' => new \stdClass()], ['filter' => 'form-object']));
        self::assertSame('0=value', QuerySerializer::encode(['0' => (object) ['0' => 'value']], ['0' => 'form-object-explode']));
        self::assertSame('filter=value', QuerySerializer::encode(['filter' => (object) ['filter' => 'value']], ['filter' => 'form-object-explode']));
    }
    public function testExpandedPropertiesCannotOverwriteScalarOrOtherExpandedParameters(): void {
        foreach ([[['filter' => (object) ['code' => 'a'], 'code' => 'b'], ['filter' => 'form-object-explode']],
            [['first' => (object) ['code' => 'a'], 'second' => (object) ['code' => 'b']], ['first' => 'form-object-explode', 'second' => 'form-object-explode']]] as [$parameters, $formats]) {
            try { QuerySerializer::encode($parameters, $formats); self::fail('Query collision accepted.'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
    }
    public function testUndefinedNestingNullAndAmbiguousHeaderValuesAreRejected(): void {
        foreach ([(object) ['x' => null], (object) ['x' => [1]], (object) ['x' => new \stdClass()], (object) ['x' => INF],
            (object) ['' => 'value'], ['x' => 'value']] as $object) {
            try { QuerySerializer::simple($object, 'filter', 'object'); self::fail('Invalid shape accepted.'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
        foreach ([(object) ['x' => 'a,b'], (object) ['x,y' => 'value'],
            (object) ['x=y' => 'value'], (object) ['x' => "a\r\nX: value"], (object) ['x' => ' value']] as $object) {
            try { QuerySerializer::simple($object, 'X-Filter', 'object-explode', true); self::fail('Ambiguous header accepted.'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
    }
    public function testTypedDtoExtractionDirectionsAndMutationsInBothTransports(): void {
        $expected = 'https://example.test/items/code,a%2Cb,active,false?filter=code,safe,active,false&body=query%20body';
        $http = new class($expected) implements ClientInterface {
            public function __construct(private string $expected) {}
            public function sendRequest(RequestInterface $request): ResponseInterface {
                TestCase::assertSame($this->expected, (string) $request->getUri());
                TestCase::assertSame('code=safe,active=false', $request->getHeaderLine('X-Filter'));
                TestCase::assertSame('{"name":"Ada"}', (string) $request->getBody());
                return new Response(204);
            }
        };
        foreach ([false, true] as $psr) {
            $transport = new FakeTransport(new HttpResponse(204, ''));
            $client = FlatParameterClient::connect('https://example.test', httpClient: $http, transport: $psr ? null : $transport);
            $path = new FlatParameterPath((object) ['selector' => (object) ['code' => 'a,b', 'active' => false, 'server' => 'hidden']]);
            $params = new FlatParameterPayload((object) ['filter' => (object) ['code' => 'safe', 'active' => false, 'server' => 'hidden'], 'queryBody' => 'query body', 'body' => (object) ['name' => 'Ada']]);
            $client->submit($path, $params);
            if (!$psr) { self::assertSame($expected, $transport->sent[0]['url']); self::assertSame('code=safe,active=false', $transport->sent[0]['headers']['X-Filter']); }
            $path->selector->code = '';
            try { $client->submit($path, $params); self::fail('Nested mutation accepted.'); }
            catch (\UnexpectedValueException) { self::assertCount($psr ? 0 : 1, $transport->sent); }
        }
    }
}
