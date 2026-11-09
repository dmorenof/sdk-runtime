<?php
declare(strict_types=1);
namespace Dmorenof\SdkRuntime\Tests;
use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\Map;
use Dmorenof\SdkRuntime\QuerySerializer;
use Dmorenof\SdkRuntime\Tests\Fixtures\User;
use Dmorenof\SdkRuntime\Tests\Fixtures\Tags;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;
class ContentPath extends DTO { public const array OPERATION_FIELDS = ['path' => ['id' => 'id']]; public User $id; }
class ContentParams extends DTO {
    public const bool OPERATION_BODY = true;
    public const array OPERATION_FIELDS = ['query' => ['body' => 'queryBody', 'labels' => 'labels', 'text' => 'text'], 'header' => ['X-Filter' => 'filter', 'Cookie' => 'raw'], 'cookie' => ['session' => 'session', 'theme' => 'theme']];
    public User $queryBody; public Tags $labels; public string $text; public User $filter; public string $raw; public string $session; public string $theme; public User $body;
}
class ContentClient extends ApiClient {
    public function call(ContentPath $path, ContentParams $params): void {
        $this->executeArguments(['method' => 'POST', 'path' => '/items/{id}', 'success' => [204], 'parameterContent' => ['path' => ['id' => 'application/json'], 'query' => ['body' => 'application/json', 'labels' => 'application/json', 'text' => 'text/plain'], 'header' => ['X-Filter' => 'application/json'], 'cookie' => ['session' => 'text/plain']]], $path, payload: $params);
    }
    public function raw(array $metadata): void { $this->executeOperation(['method' => 'GET', 'path' => '/items', 'success' => [204]] + $metadata); }
    public function scalar(string $id): void { $this->executeOperation(['method' => 'GET', 'path' => '/{id}', 'success' => [204], 'validation' => ['id' => ['minLength' => 1]], 'parameterContent' => ['path' => ['id' => 'application/json']]], pathParameters: ['id' => $id]); }
}
final class ParameterContentTest extends TestCase {
    public function testContentReusesRequestDirectionCollectionsAndNumericMapKeys(): void {
        $user = new User((object) ['name' => 'a/b', 'age' => 0, 'id' => 7]);
        self::assertSame('{"name":"a\/b","age":0}', QuerySerializer::content($user, 'application/json'));
        self::assertSame('["x\/y",""]', QuerySerializer::content(new Tags(['x/y', '']), 'application/json'));
        self::assertSame('{"0":"value"}', QuerySerializer::content(new Map((object) ['0' => 'value']), 'application/json'));
        self::assertSame('[]', QuerySerializer::content([], 'application/json'));
        self::assertSame('false', QuerySerializer::content(false, 'application/json'));
        self::assertSame('0', QuerySerializer::content(0, 'application/json'));
        self::assertSame('""', QuerySerializer::content('', 'application/json'));
        self::assertSame('a+/b==', QuerySerializer::content('a+/b==', 'text/plain'));
        $sparse = new Tags(['first', 'second']); unset($sparse[0]);
        self::assertSame('["second"]', QuerySerializer::content($sparse, 'application/json'));
        $user->tags = $sparse;
        self::assertSame('{"name":"a\/b","age":0,"tags":["second"]}', QuerySerializer::content($user, 'application/json'));
        $user->age = -1; $this->expectException(\UnexpectedValueException::class); QuerySerializer::content($user, 'application/json');
    }
    public function testNativeJsonErrorsAndPlainHeaderOctetsFailBeforeTransport(): void {
        foreach (['null' => null, 'object' => new \stdClass()] as $value) {
            try { QuerySerializer::content($value, 'application/json'); self::fail('Untyped or null content accepted.'); } catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
        foreach (["x\r\nX: bad", ' leading', 'trailing '] as $value) {
            try { QuerySerializer::content($value, 'text/plain', true); self::fail('Header changed content bytes.'); } catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
        self::assertSame('a,b=c', QuerySerializer::content('a,b=c', 'text/plain', true));
        self::assertSame('"line\\nnext"', QuerySerializer::content("line\nnext", 'application/json', true));
        foreach ([INF, "\xFF"] as $value) {
            try { QuerySerializer::content($value, 'application/json'); self::fail('Invalid JSON accepted.'); } catch (\JsonException) { self::assertTrue(true); }
        }
    }
    public function testBothTransportsEncodeContentOnceAndKeepBodyAndRawCookiesIndependent(): void {
        $user = new User((object) ['name' => 'a/b', 'age' => 0, 'id' => 7]);
        $path = new ContentPath(); $path->id = $user;
        $params = new ContentParams(); $params->queryBody = $user; $params->filter = $user; $params->body = $user;
        $params->labels = new Tags(['x/y', '']); $params->text = '100% / ñ'; $params->raw = 'caller=ok'; $params->session = 'a+/b=='; $params->theme = 'a/b';
        $json = '{"name":"a\/b","age":0}';
        $expected = 'https://example.test/items/' . rawurlencode($json) . '?body=' . rawurlencode($json) . '&labels=' . rawurlencode('["x\/y",""]') . '&text=100%25%20%2F%20%C3%B1';
        $legacy = new FakeTransport(new HttpResponse(204, '')); $client = new ContentClient('https://example.test', transport: $legacy); $client->call($path, $params);
        self::assertSame($expected, $legacy->sent[0]['url']); self::assertSame($json, $legacy->sent[0]['headers']['X-Filter']); self::assertSame($json, $legacy->sent[0]['body']);
        self::assertSame('caller=ok; session=a+/b==; theme=a%2Fb', $legacy->sent[0]['headers']['Cookie']);
        $http = new class($expected, $json) implements \Psr\Http\Client\ClientInterface {
            public int $calls = 0;
            public function __construct(private string $expected, private string $json) {}
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
                ++$this->calls; TestCase::assertSame($this->expected, (string) $request->getUri()); TestCase::assertSame($this->json, $request->getHeaderLine('X-Filter'));
                TestCase::assertSame('default=base; caller=ok; session=a+/b==; theme=a%2Fb', $request->getHeaderLine('Cookie')); return new \GuzzleHttp\Psr7\Response(204);
            }
        };
        $client = new ContentClient('https://example.test', httpClient: $http, headers: ['COOKIE' => 'default=base']); $client->call($path, $params);
        $params->session = 'bad;cookie'; try { $client->call($path, $params); self::fail('Invalid cookie reached HTTP.'); } catch (\UnexpectedValueException) { self::assertSame(1, $http->calls); }
        try { $client->scalar(''); self::fail('Serialization bypassed logical path validation.'); } catch (\UnexpectedValueException) { self::assertSame(1, $http->calls); }
    }
    public function testConflictingOrUnsupportedMetadataCannotReachTransport(): void {
        $legacy = new FakeTransport(new HttpResponse(204, '')); $client = new ContentClient('https://example.test', transport: $legacy);
        foreach ([['parameterContent' => ['unknown' => []]], ['parameterContent' => ['query' => 'bad']], ['parameterContent' => ['query' => ['q' => 'application/xml']]], ['parameterContent' => ['cookie' => ['q' => 'application/json']]],
            ['parameterContent' => ['query' => ['q' => 'application/json']], 'querySerialization' => ['q' => 'multi']], ['parameterContent' => ['query' => ['q' => 'application/json']], 'queryAllowReserved' => ['q']]] as $metadata) {
            try { $client->raw($metadata); self::fail('Invalid metadata reached HTTP.'); } catch (\InvalidArgumentException) { self::assertSame([], $legacy->sent); }
        }
    }
}
