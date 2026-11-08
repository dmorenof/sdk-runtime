<?php
declare(strict_types=1);
namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\ApiException;
use Dmorenof\SdkRuntime\Tests\Fixtures\Address;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Transport\HttpMiddlewareInterface;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class BinaryResponseClient extends ApiClient
{
    public function download(): ResponseInterface
    {
        return $this->executeOperation(['method' => 'GET', 'path' => '/files', 'success' => [200, 206, 204],
            'responseType' => ResponseInterface::class, 'errors' => [404 => Address::class]],
            queryParameters: ['body' => 'query body'], headerParameters: ['X-Trace' => 'trace']);
    }
}
final class BinaryResponseTest extends TestCase
{
    private function http(ResponseInterface $response): ClientInterface
    {
        return new class($response) implements ClientInterface {
            public function __construct(private ResponseInterface $response) {}
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                TestCase::assertSame('application/octet-stream', $request->getHeaderLine('Accept'));
                TestCase::assertSame('trace', $request->getHeaderLine('X-Trace'));
                TestCase::assertSame('body=query%20body', $request->getUri()->getQuery());
                return $this->response;
            }
        };
    }
    public function testPreservesIdentityMultiValueHeadersAndUnreadNonSeekableBytes(): void
    {
        $stream = new NoSeekStream(Utils::streamFor("\x00\xff\r\n"));
        $response = new Response(206, ['X-Values' => ['one', 'two']], $stream);
        $client = BinaryResponseClient::connect('https://example.test', httpClient: $this->http($response));
        self::assertSame($response, $client->download());
        self::assertSame(0, $stream->tell());
        self::assertSame(['one', 'two'], $response->getHeader('X-Values'));
        self::assertSame("\x00\xff\r\n", $stream->getContents());
    }
    public function testRewindsSeekableBodyAfterMiddlewareInspection(): void
    {
        $response = new Response(200, [], 'original');
        $client = BinaryResponseClient::connect('https://example.test', httpClient: $this->http($response));
        $client->withMiddleware(new class implements HttpMiddlewareInterface {
            public function beforeRequest(RequestInterface $request): RequestInterface { return $request; }
            public function afterResponse(RequestInterface $request, ResponseInterface $response): ResponseInterface
            {
                $response->getBody()->read(3);
                return $response->withHeader('X-Inspected', 'yes');
            }
        });
        $result = $client->download();
        self::assertSame('yes', $result->getHeaderLine('X-Inspected'));
        self::assertSame('original', $result->getBody()->getContents());
    }
    public function testEmptySuccessfulResponsesRemainPsrAndLegacyTransportIsCompatible(): void
    {
        foreach ([200, 204, 206] as $status) {
            $transport = new FakeTransport(new HttpResponse($status, '', ['X-Test' => 'legacy']));
            $response = BinaryResponseClient::connect('https://example.test', transport: $transport)->download();
            self::assertSame($status, $response->getStatusCode());
            self::assertSame('', (string) $response->getBody());
            self::assertSame('legacy', $response->getHeaderLine('X-Test'));
        }
    }
    public function testTypedJsonAndRawErrorsKeepTheirExistingContract(): void
    {
        foreach (['{"city":"Madrid"}', 'raw error'] as $body) {
            $client = BinaryResponseClient::connect('https://example.test', httpClient: $this->http(new Response(404, [], $body)));
            try { $client->download(); self::fail('Expected ApiException.'); }
            catch (ApiException $e) {
                self::assertSame(404, $e->response->status);
                self::assertSame($body, $e->response->body);
                if ($body[0] === '{') { self::assertInstanceOf(Address::class, $e->error); }
                else { self::assertNull($e->error); }
            }
        }
    }
}
