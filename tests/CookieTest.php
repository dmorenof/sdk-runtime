<?php
declare(strict_types=1);
namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\CookieHeader;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\Security\SecurityApplier;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

class CookieParams extends DTO
{
    public const array OPERATION_FIELDS = ['cookie' => ['theme' => 'theme', 'enabled' => 'enabled', 'count' => 'count', 'empty' => 'empty'], 'query' => ['body' => 'queryBody'], 'header' => ['cookie' => 'raw']];
    protected const array VALIDATION = ['count' => ['minimum' => 0]];
    public string $theme;
    public bool $enabled;
    public int $count;
    public string $empty;
    public string $queryBody;
    public string $raw;
}
class CookieClient extends ApiClient
{
    protected const array SECURITY_SCHEMES = ['Session' => ['type' => 'apiKey', 'scheme' => null, 'location' => 'cookie', 'parameterName' => 'session']];
    public function call(CookieParams $params): void
    {
        $this->executeArguments(['method' => 'GET', 'path' => '/items', 'security' => [['Session' => []]], 'success' => [204]], payload: $params);
    }
}
final class CookieTest extends TestCase
{
    public function testRfcCookieAssemblyPreservesValuesAndNameCase(): void
    {
        self::assertSame(['Cookie' => 'id=0; ID=false; empty=; quoted="a=b"; token=a+/b=='], CookieHeader::merge(['cookie' => 'id=0; ID=false; empty='], ['COOKIE' => 'quoted="a=b"; token=a+/b=='], ['Cookie' => 'id=0']));
        self::assertSame(['X-Test' => 'new'], CookieHeader::merge(['X-Test' => 'old'], ['X-Test' => 'new', 'Cookie' => '']));
        self::assertSame(['Cookie' => '0=value'], CookieHeader::merge(['Cookie' => '0=value']));
    }
    public function testMalformedAndConflictingCookiesFailWithoutLeakingValues(): void
    {
        foreach (['missing', 'a=a,b', 'a=a b', 'a=a\\b', "a=a\r\nX: b", 'a=ñ', 'a="unterminated', 'a=b;', 'a=b; a=c', '=value', 'a[b]=x'] as $header) {
            try { CookieHeader::merge(['Cookie' => $header]); self::fail('Invalid cookie accepted.'); }
            catch (\UnexpectedValueException $e) { self::assertStringNotContainsString($header, $e->getMessage()); }
        }
    }
    public function testDtoCookiesAreIndependentAndValidateMutationsInBothTransports(): void
    {
        $params = new CookieParams((object) ['theme' => 'a/b,ñ', 'enabled' => false, 'count' => 0, 'empty' => '', 'queryBody' => 'query body', 'raw' => 'caller=ok']);
        $expected = 'caller=ok; theme=a%2Fb%2C%C3%B1; enabled=false; count=0; empty=; session=a+/b==';
        $legacy = new FakeTransport(new HttpResponse(204, '', []));
        $client = new CookieClient('https://example.test', securityCredentials: new SecurityCredentials(['Session' => 'a+/b==']), transport: $legacy);
        $client->call($params);
        self::assertSame('https://example.test/items?body=query%20body', $legacy->sent[0]['url']);
        self::assertSame($expected, $legacy->sent[0]['headers']['Cookie']);
        $http = new class($expected) implements \Psr\Http\Client\ClientInterface {
            public int $calls = 0;
            public function __construct(private string $expected) {}
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
                ++$this->calls;
                TestCase::assertSame(['default=base; ' . $this->expected], $request->getHeader('Cookie'));
                return new \GuzzleHttp\Psr7\Response(204);
            }
        };
        $client = new CookieClient('https://example.test', httpClient: $http, headers: ['COOKIE' => 'default=base'], securityCredentials: new SecurityCredentials(['Session' => 'a+/b==']));
        $client->call($params);
        $params->count = -1;
        try { $client->call($params); self::fail('Mutation reached HTTP.'); }
        catch (\UnexpectedValueException) { self::assertSame(1, $http->calls); }
    }
    public function testSecurityCookiesRespectAlternativesAndRejectConflicts(): void
    {
        $schemes = ['Session' => ['type' => 'apiKey', 'scheme' => null, 'location' => 'cookie', 'parameterName' => 'session'], 'Other' => ['type' => 'apiKey', 'scheme' => null, 'location' => 'cookie', 'parameterName' => 'session']];
        $query = [];
        $headers = ['cookie' => 'theme=dark'];
        SecurityApplier::apply(new SecurityCredentials(['Session' => 'abc']), [['Other' => []], ['Session' => []]], $schemes, $headers, $query);
        self::assertSame(['Cookie' => 'theme=dark; session=abc'], $headers);
        foreach ([['Session' => 'abc', 'Other' => 'different'], ['Session' => 'not;safe']] as $credentials) {
            $headers = ['Cookie' => 'theme=dark'];
            try { SecurityApplier::apply(new SecurityCredentials($credentials), [array_fill_keys(array_keys($credentials), [])], $schemes, $headers, $query); self::fail('Invalid authentication accepted.'); }
            catch (\UnexpectedValueException) { self::assertSame(['Cookie' => 'theme=dark'], $headers); }
        }
        $headers = ['Cookie' => 'session=other'];
        $this->expectException(\UnexpectedValueException::class);
        SecurityApplier::apply(new SecurityCredentials(['Session' => 'abc']), [['Session' => []]], $schemes, $headers, $query);
    }
}
