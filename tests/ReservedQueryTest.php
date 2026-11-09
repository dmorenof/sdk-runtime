<?php
declare(strict_types=1);
namespace Dmorenof\SdkRuntime\Tests;
use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\QuerySerializer;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

class ReservedQueryBody extends DTO { public string $name; }
class ReservedQueryParams extends DTO {
    public const bool OPERATION_BODY = true;
    public const array OPERATION_FIELDS = ['query' => ['q' => 'q', 'body' => 'queryBody'], 'header' => ['X-Trace' => 'trace']];
    protected const array VALIDATION = ['q' => ['minLength' => 1]];
    public string $q; public string $queryBody; public string $trace; public ReservedQueryBody $body;
}
class ReservedQueryClient extends ApiClient {
    protected const array SECURITY_SCHEMES = ['Key' => ['type' => 'apiKey', 'scheme' => null, 'location' => 'query', 'parameterName' => 'k']];
    public function call(ReservedQueryParams $params): void {
        $this->executeArguments(['method' => 'POST', 'path' => '/items', 'success' => [204], 'queryAllowReserved' => ['q', 'body', 'k'], 'security' => [['Key' => []]]], payload: $params);
    }
}
final class ReservedQueryTest extends TestCase {
    public function testReservedExpansionKeepsSafeCharactersAndValidTriplesOnlyInValues(): void {
        $value = ":/?@!\$'()*,; ñ%2f%20%ZZ%";
        self::assertSame('q%2Fx=' . rawurlencode($value), QuerySerializer::encode(['q/x' => $value]));
        self::assertSame("q%2Fx=:/?@!\$'()*,;%20%C3%B1%2f%20%25ZZ%25", QuerySerializer::encode(['q/x' => $value], allowReserved: ['q/x']));
        self::assertSame('0=zero/0&false=false&zero=0', QuerySerializer::encode(['0' => 'zero/0', 'false' => false, 'zero' => 0, 'missing' => null], allowReserved: ['0', 'false', 'zero']));
        self::assertSame('q=%252F', QuerySerializer::encode(['q' => '%2F'], allowReserved: []));
    }
    public function testListsAndFlatObjectsKeepExistingShapesNamesAndDelimiters(): void {
        foreach (['multi' => 'q=a/b&q=c?d', 'csv' => 'q=a/b,c?d', 'ssv' => 'q=a/b%20c?d', 'pipes' => 'q=a/b%7Cc?d', 'tsv' => 'q=a/b%09c?d'] as $format => $expected) {
            self::assertSame($expected, QuerySerializer::encode(['q' => ['a/b', 'c?d']], ['q' => $format], allowReserved: ['q']));
            self::assertSame('', QuerySerializer::encode(['q' => []], ['q' => $format], allowReserved: ['q']));
        }
        self::assertSame('q=a%2Cb,c/d', QuerySerializer::encode(['q' => ['a%2Cb', 'c/d']], ['q' => 'csv'], allowReserved: ['q']));
        // OpenAPI 3.1.2 Appendix C.4.2: manually pre-encoded plus, reserved slash and native caret encoding.
        self::assertSame('a=x%2By&b=x/y&c=x%5Ey&words=math%20is%20fun', QuerySerializer::encode(
            ['formulas' => (object) ['a' => 'x%2By', 'b' => 'x/y', 'c' => 'x^y'], 'words' => ['math', 'is', 'fun']],
            ['formulas' => 'form-object-explode', 'words' => 'ssv'], allowReserved: ['formulas']));
        $object = (object) ['a/b' => 'x/y', 'count' => 0];
        self::assertSame('q=a/b,x/y,count,0', QuerySerializer::encode(['q' => $object], ['q' => 'form-object'], allowReserved: ['q']));
        self::assertSame('a%2Fb=x/y&count=0', QuerySerializer::encode(['q' => $object], ['q' => 'form-object-explode'], allowReserved: ['q']));
        self::assertSame('q%5Ba%2Fb%5D=x/y&q%5Bcount%5D=0', QuerySerializer::encode(['q' => $object], ['q' => 'deepObject'], allowReserved: ['q']));
    }
    public function testRawQuerySyntaxAndAmbiguousStructuralDataAreRejectedBeforeTransport(): void {
        foreach (['a&b', 'a=b', 'a+b', '#fragment', '[array]', 'x]'] as $value) {
            try { QuerySerializer::encode(['q' => $value], allowReserved: ['q']); self::fail('Query syntax accepted as data.'); } catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
        foreach (['csv' => 'a,b', 'ssv' => 'a%20b', 'pipes' => 'a%7cb', 'tsv' => 'a%09b'] as $format => $value) {
            try { QuerySerializer::encode(['q' => [$value]], ['q' => $format], allowReserved: ['q']); self::fail('Ambiguous delimiter accepted.'); } catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
        self::assertSame('q=%23%5B%5D%26%3D%2B', QuerySerializer::encode(['q' => '%23%5B%5D%26%3D%2B'], allowReserved: ['q']));
        foreach ([[''], [false], [0]] as $names) {
            try { QuerySerializer::encode([], allowReserved: $names); self::fail('Invalid metadata accepted.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $this->expectException(\InvalidArgumentException::class); QuerySerializer::encode(['q' => 'a/b'], encoding: PHP_QUERY_RFC1738, allowReserved: ['q']);
    }
    public function testBothTransportsKeepCredentialsHeadersAndJsonBodyNormallyEncoded(): void {
        $params = new ReservedQueryParams((object) ['q' => 'a/b%26c?x', 'queryBody' => 'query/body', 'trace' => 'h/x', 'body' => (object) ['name' => 'body/x']]);
        $legacy = new FakeTransport(new HttpResponse(204, ''));
        $client = new ReservedQueryClient('https://example.test', securityCredentials: new SecurityCredentials(['Key' => '%2F']), transport: $legacy);
        $client->call($params);
        self::assertSame('https://example.test/items?q=a/b%26c?x&body=query/body&k=%252F', $legacy->sent[0]['url']);
        self::assertSame('{"name":"body\/x"}', $legacy->sent[0]['body']);
        self::assertSame('h/x', $legacy->sent[0]['headers']['X-Trace']);
        $http = new class implements \Psr\Http\Client\ClientInterface {
            public int $calls = 0;
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
                ++$this->calls; TestCase::assertSame('https://example.test/items?q=a/b%26c?x&body=query/body&k=%252F', (string) $request->getUri()); return new \GuzzleHttp\Psr7\Response(204);
            }
        };
        $client = new ReservedQueryClient('https://example.test', httpClient: $http, securityCredentials: new SecurityCredentials(['Key' => '%2F'])); $client->call($params);
        $params->q = 'bad&injected=value';
        try { $client->call($params); self::fail('Unsafe data reached HTTP.'); } catch (\UnexpectedValueException) { self::assertSame(1, $http->calls); }
    }
}
