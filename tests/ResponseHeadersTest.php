<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\ApiException;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use Dmorenof\SdkRuntime\Transport\ResponseHeaders;
use Dmorenof\SdkRuntime\Transport\TransportInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ResponseHeadersProbe extends ApiClient
{
    private const array LIST = [
        'method' => 'GET',
        'path' => '/items',
        'success' => [200],
        'noContentAllowed' => true,
        'responseHeaders' => [
            200 => [
                'X-Rate-Limit' => ['type' => 'integer', 'required' => true, 'rules' => ['minimum' => 0]],
                'X-Cursor' => ['type' => 'string'],
                'X-Tags' => ['type' => 'array', 'items' => 'string'],
            ],
            'default' => ['X-Request-Id' => ['type' => 'string', 'required' => true]],
        ],
    ];

    public function list(): mixed
    {
        return $this->executeOperation(self::LIST);
    }
}

final class ResponseHeadersTest extends TestCase
{
    public function testLookupIsCaseInsensitiveAndKeepsReceivedNames(): void
    {
        $headers = new ResponseHeaders(['Content-Type' => ['application/json'], 'X-Id' => ['a', 'b']]);

        self::assertTrue($headers->has('content-type'));
        self::assertSame('application/json', $headers->first('CONTENT-TYPE'));
        self::assertSame(['a', 'b'], $headers->values('x-id'));
        self::assertSame(['Content-Type', 'X-Id'], $headers->names());
        self::assertNull($headers->first('missing'));
        self::assertSame([], $headers->values('missing'));
    }

    public function testUndeclaredHeadersReturnTheirRawFirstLine(): void
    {
        $headers = new ResponseHeaders(['X-Anything' => ['42']]);

        self::assertSame('42', $headers->get('x-anything'));
        self::assertNull($headers->get('x-none'));
    }

    public function testConvertsDeclaredScalarTypes(): void
    {
        $headers = new ResponseHeaders(
            ['X-Int' => ['-7'], 'X-Num' => ['1.5'], 'X-Whole' => ['3'], 'X-Bool' => ['TRUE'], 'X-Str' => ['ab']],
            [
                'x-int' => ['type' => 'integer'],
                'X-Num' => ['type' => 'number'],
                'X-Whole' => ['type' => 'number'],
                'X-Bool' => ['type' => 'boolean'],
                'X-Str' => ['type' => 'string'],
            ],
        );

        self::assertSame(-7, $headers->get('X-Int'));
        self::assertSame(1.5, $headers->get('X-Num'));
        self::assertSame(3, $headers->get('X-Whole'));
        self::assertTrue($headers->get('X-Bool'));
        self::assertSame('ab', $headers->get('X-Str'));
    }

    public function testConvertsSimpleLists(): void
    {
        $headers = new ResponseHeaders(
            ['X-Ids' => ['1, 2', '3'], 'X-Empty' => ['']],
            ['X-Ids' => ['type' => 'array', 'items' => 'integer'], 'X-Empty' => ['type' => 'array']],
        );

        self::assertSame([1, 2, 3], $headers->get('X-Ids'));
        self::assertSame([], $headers->get('X-Empty'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedValues(): iterable
    {
        yield 'integer text' => ['integer', 'abc'];
        yield 'integer decimal' => ['integer', '1.5'];
        yield 'integer overflow' => ['integer', '99999999999999999999'];
        yield 'integer leading zero' => ['integer', '007'];
        yield 'number text' => ['number', 'NaN'];
        yield 'number infinity' => ['number', 'INF'];
        yield 'boolean yes' => ['boolean', 'yes'];
        yield 'boolean one' => ['boolean', '1'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedValues')]
    public function testRejectsValuesThatContradictTheDeclaration(string $type, string $value): void
    {
        $headers = new ResponseHeaders(['X-V' => [$value]], ['X-V' => ['type' => $type]]);

        $this->expectException(\UnexpectedValueException::class);
        $headers->get('X-V');
    }

    public function testRequiredHeaderMustBePresentButOptionalMayBeAbsent(): void
    {
        $headers = new ResponseHeaders([], ['X-Need' => ['type' => 'string', 'required' => true], 'X-Opt' => ['type' => 'string']]);

        self::assertNull($headers->get('X-Opt'));
        $this->expectException(\UnexpectedValueException::class);
        $headers->get('X-Need');
    }

    public function testAppliesDeclaredRules(): void
    {
        $headers = new ResponseHeaders(['X-N' => ['-1']], ['X-N' => ['type' => 'integer', 'rules' => ['minimum' => 0]]]);

        $this->expectException(\Throwable::class);
        $headers->get('X-N');
    }

    public function testRejectsInvalidDeclarations(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ResponseHeaders([], ['X' => ['type' => 'object']]);
    }

    public function testParsesRepeatedSetCookieLinesSeparately(): void
    {
        $headers = new ResponseHeaders(['Set-Cookie' => [
            'sid=abc; Path=/; HttpOnly; Expires=Wed, 21 Oct 2026 07:28:00 GMT',
            'theme=dark; Max-Age=60; Secure',
        ]]);

        $cookies = $headers->cookies();

        self::assertCount(2, $cookies);
        self::assertSame('sid', $cookies[0]->getName());
        self::assertSame('abc', $cookies[0]->getValue());
        self::assertTrue($cookies[0]->getHttpOnly());
        self::assertSame('dark', $cookies[1]->getValue());
        self::assertTrue($cookies[1]->getSecure());
        self::assertSame(60, $cookies[1]->getMaxAge());
    }

    public function testDebugOutputNeverContainsValues(): void
    {
        self::assertStringNotContainsString('secret-token', print_r(new ResponseHeaders(['Authorization' => ['secret-token']]), true));
    }

    private function psrClient(ResponseInterface $response): ClientInterface
    {
        return new class ($response) implements ClientInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    public function testLastResponseExposesTypedHeadersThroughPsr18(): void
    {
        $client = ResponseHeadersProbe::connect('https://api.example.com', $this->psrClient(new Response(200, [
            'X-Rate-Limit' => '12', 'X-Tags' => ['a', 'b'], 'Set-Cookie' => ['a=1', 'b=2'],
        ], '')));

        self::assertNull($client->lastResponse());
        $client->list();
        $info = $client->lastResponse();

        self::assertNotNull($info);
        self::assertSame(200, $info->status);
        self::assertSame('GET /items', $info->operation);
        self::assertSame(12, $info->headers->get('x-rate-limit'));
        self::assertSame(['a', 'b'], $info->headers->get('X-Tags'));
        self::assertNull($info->headers->get('X-Cursor'));
        self::assertCount(2, $info->headers->cookies());
    }

    public function testLastResponseIsRecordedForErrorsWithTheirOwnDeclarations(): void
    {
        $client = ResponseHeadersProbe::connect('https://api.example.com', $this->psrClient(new Response(503, ['X-Request-Id' => 'r-9'], '{}')));

        try {
            $client->list();
            self::fail('Expected ApiException.');
        } catch (ApiException) {
            self::assertSame(503, $client->lastResponse()?->status);
            self::assertSame('r-9', $client->lastResponse()->headers->get('X-Request-Id'));
        }
    }

    public function testLegacyTransportHeadersAreAlsoExposed(): void
    {
        $transport = new class implements TransportInterface {
            public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
            {
                return new HttpResponse(200, '', ['x-rate-limit' => '5']);
            }
        };
        $client = ResponseHeadersProbe::connect('https://api.example.com', transport: $transport);

        $client->list();

        self::assertSame(5, $client->lastResponse()?->headers->get('X-Rate-Limit'));
    }

    public function testMissingRequiredDeclaredHeaderFailsOnlyWhenRead(): void
    {
        $client = ResponseHeadersProbe::connect('https://api.example.com', $this->psrClient(new Response(200, [], '')));

        $client->list(); // the call itself succeeds
        $this->expectException(\UnexpectedValueException::class);
        $client->lastResponse()?->headers->get('X-Rate-Limit');
    }
}
