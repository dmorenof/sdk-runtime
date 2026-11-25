<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\Tests\Support\ScriptedServer;
use Dmorenof\SdkRuntime\Tests\Support\Wire;
use Dmorenof\SdkRuntime\Transport\HttpMiddlewareInterface;
use Dmorenof\SdkRuntime\Transport\Psr18Transport;
use Dmorenof\SdkRuntime\Transport\RedirectException;
use Dmorenof\SdkRuntime\Transport\RedirectPolicy;
use Dmorenof\SdkRuntime\Transport\RetryPolicy;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The transport on a real socket: the default Guzzle client the SDKs use, talking to a scripted server on 127.0.0.1
 * (see Support/ScriptedServer.php). The other transport tests replace the PSR-18 client with a fake; these prove that retries,
 * redirects, timeouts, compression and middleware work with the bytes that actually leave and arrive.
 */
final class RealTransportTest extends TestCase
{
    /** @var list<ScriptedServer> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
    }

    /** @param list<array<string, mixed>> $script */
    private function server(array $script = []): ScriptedServer
    {
        return $this->servers[] = ScriptedServer::start($script);
    }

    /**
     * The client of the SDKs by default (same options as ApiClient), on the transport under test.
     *
     * @param list<HttpMiddlewareInterface> $middleware
     * @param array<string, mixed> $guzzle
     * @param-out list<int> $sleeps
     */
    private function transport(?RetryPolicy $retry = null, ?RedirectPolicy $redirect = null, array $middleware = [], array $guzzle = [], ?array &$sleeps = null): Psr18Transport
    {
        $sleeps = [];
        return Wire::transport(new Client($guzzle + ['http_errors' => false]), [], $retry, static function (int $ms) use (&$sleeps): void {
            $sleeps[] = $ms;
        }, $redirect, $middleware);
    }

    public function testRetriesTransientStatusesAndSucceeds(): void
    {
        $server = $this->server([['status' => 503], ['status' => 429], ['status' => 200, 'body' => 'fine']]);
        $transport = $this->transport(new RetryPolicy(baseDelayMs: 0, maxDelayMs: 0));

        $response = Wire::send($transport, 'GET', $server->url('/items'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('fine', (string) $response->getBody());
        self::assertCount(3, $server->requests());
    }

    public function testReturnsTheLastAnswerWhenAttemptsRunOut(): void
    {
        $server = $this->server([['status' => 503, 'body' => 'one'], ['status' => 503, 'body' => 'two']]);
        $transport = $this->transport(new RetryPolicy(maxAttempts: 2, baseDelayMs: 0, maxDelayMs: 0));

        $response = Wire::send($transport, 'GET', $server->url());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('two', (string) $response->getBody());
        self::assertCount(2, $server->requests());
    }

    public function testDoesNotReplayAPostByDefault(): void
    {
        $server = $this->server([['status' => 503], ['status' => 200]]);
        $transport = $this->transport(new RetryPolicy(baseDelayMs: 0, maxDelayMs: 0));

        $response = Wire::send($transport, 'POST', $server->url('/orders'), ['Content-Type' => 'application/json'], '{"a":1}');

        self::assertSame(503, $response->getStatusCode());
        self::assertCount(1, $server->requests());
    }

    public function testReplaysAPostWhenThePolicyAllowsItWithTheSameBody(): void
    {
        $server = $this->server([['status' => 502], ['status' => 200]]);
        $transport = $this->transport(new RetryPolicy(methods: ['POST'], baseDelayMs: 0, maxDelayMs: 0));

        $response = Wire::send($transport, 'POST', $server->url('/orders'), ['Content-Type' => 'application/json'], '{"a":1}');

        self::assertSame(200, $response->getStatusCode());
        $requests = $server->requests();
        self::assertCount(2, $requests);
        self::assertSame('{"a":1}', $requests[0]['body']);
        self::assertSame('{"a":1}', $requests[1]['body']);
    }

    public function testWaitsAsLongAsRetryAfterAsks(): void
    {
        $server = $this->server([['status' => 429, 'headers' => ['Retry-After' => '1']], ['status' => 200]]);
        $transport = Wire::transport(new Client(['http_errors' => false]), [], new RetryPolicy(maxDelayMs: 5000));

        $start = microtime(true);
        $response = Wire::send($transport, 'GET', $server->url());
        $elapsed = microtime(true) - $start;

        self::assertSame(200, $response->getStatusCode());
        self::assertGreaterThanOrEqual(0.95, $elapsed);
        self::assertLessThan(4.0, $elapsed);
    }

    public function testFollowsARedirectAndKeepsTheMethodOn307(): void
    {
        $server = $this->server();
        $server->script([['status' => 307, 'headers' => ['Location' => '/moved']], ['status' => 201, 'body' => 'created']]);
        $transport = $this->transport(redirect: new RedirectPolicy());

        $response = Wire::send($transport, 'POST', $server->url('/orders'), ['Content-Type' => 'application/json'], '{"a":1}');

        self::assertSame(201, $response->getStatusCode());
        $requests = $server->requests();
        self::assertSame('POST /moved', $requests[1]['method'] . ' ' . $requests[1]['target']);
        self::assertSame('{"a":1}', $requests[1]['body']);
    }

    public function testATemporaryRedirectOn302TurnsAPostIntoAGetWithoutBody(): void
    {
        $server = $this->server([['status' => 302, 'headers' => ['Location' => '/elsewhere']], ['status' => 200]]);
        $transport = $this->transport(redirect: new RedirectPolicy());

        Wire::send($transport, 'POST', $server->url('/orders'), ['Content-Type' => 'application/json'], '{"a":1}');

        $requests = $server->requests();
        self::assertSame('GET', $requests[1]['method']);
        self::assertSame('', $requests[1]['body']);
    }

    public function testAnotherOriginGetsNoCredentialsButTheSameOriginDoes(): void
    {
        $origin = $this->server();
        $other = $this->server([['status' => 200, 'body' => 'there']]);
        $origin->script([
            ['status' => 302, 'headers' => ['Location' => '/same']],
            ['status' => 302, 'headers' => ['Location' => $other->url('/other')]],
        ]);
        $transport = $this->transport(redirect: new RedirectPolicy());

        $response = Wire::send($transport, 'GET', $origin->url('/start'), ['Authorization' => 'Bearer secret', 'Accept' => 'application/json']);

        self::assertSame('there', (string) $response->getBody());
        $first = $origin->requests();
        self::assertSame(['Bearer secret'], $first[1]['headers']['authorization']);
        $second = $other->requests();
        self::assertArrayNotHasKey('authorization', $second[0]['headers']);
        self::assertSame(['application/json'], $second[0]['headers']['accept']);
    }

    public function testRefusesToLoopForever(): void
    {
        $server = $this->server(array_fill(0, 10, ['status' => 302, 'headers' => ['Location' => '/again']]));
        $transport = $this->transport(redirect: new RedirectPolicy(maxRedirects: 2));

        try {
            Wire::send($transport, 'GET', $server->url());
            self::fail('Expected a RedirectException.');
        } catch (RedirectException $e) {
            self::assertSame(302, $e->response->getStatusCode());
        }
        self::assertCount(3, $server->requests());
    }

    public function testWithoutAPolicyTheRedirectIsReturnedAsIs(): void
    {
        $server = $this->server([['status' => 302, 'headers' => ['Location' => '/moved']]]);
        $transport = $this->transport();

        $response = Wire::send($transport, 'GET', $server->url());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/moved', $response->getHeaderLine('Location'));
        self::assertCount(1, $server->requests());
    }

    public function testASlowServerEndsInANetworkException(): void
    {
        $server = $this->server([['status' => 200, 'delayMs' => 2000]]);
        $transport = $this->transport(guzzle: ['timeout' => 0.5]);

        $start = microtime(true);
        try {
            Wire::send($transport, 'GET', $server->url());
            self::fail('Expected a network exception.');
        } catch (NetworkExceptionInterface) {
            self::assertLessThan(1.9, microtime(true) - $start);
        }
    }

    public function testRetriesAConnectionThatIsRefusedAndThenGivesUp(): void
    {
        $server = $this->server();
        $url = $server->url();
        $server->stop();
        $this->servers = [];
        $transport = $this->transport(new RetryPolicy(maxAttempts: 3, baseDelayMs: 1, maxDelayMs: 1), sleeps: $sleeps);

        try {
            Wire::send($transport, 'GET', $url);
            self::fail('Expected a network exception.');
        } catch (NetworkExceptionInterface) {
            self::assertCount(2, $sleeps);
        }
    }

    public function testADeflatedAnswerArrivesDecoded(): void
    {
        $server = $this->server([['status' => 200, 'headers' => ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'], 'bodyBase64' => base64_encode((string) gzencode('{"a":1}'))]]);
        $transport = $this->transport();

        $response = Wire::send($transport, 'GET', $server->url());

        self::assertSame('{"a":1}', (string) $response->getBody());
        // Guzzle does not advertise compression unless `decode_content` is set explicitly, so a server that waits for an
        // Accept-Encoding never compresses for an SDK. Pinned here so that changing it is a decision, not an accident.
        self::assertArrayNotHasKey('accept-encoding', $server->requests()[0]['headers']);
    }

    public function testMiddlewareSeesTheRealRequestAndAnswer(): void
    {
        $server = $this->server([['status' => 200, 'headers' => ['X-Seen' => 'server'], 'body' => 'ok']]);
        $middleware = new class implements HttpMiddlewareInterface {
            public ?int $status = null;

            public function beforeRequest(RequestInterface $request): RequestInterface
            {
                return $request->withHeader('X-Added', 'by-middleware');
            }

            public function afterResponse(RequestInterface $request, ResponseInterface $response): ResponseInterface
            {
                $this->status = $response->getStatusCode();
                return $response->withHeader('X-After', 'yes');
            }
        };
        $transport = $this->transport(middleware: [$middleware]);

        $response = Wire::send($transport, 'GET', $server->url());

        self::assertSame(['by-middleware'], $server->requests()[0]['headers']['x-added']);
        self::assertSame(200, $middleware->status);
        self::assertSame('yes', $response->getHeaderLine('X-After'));
        self::assertSame('server', $response->getHeaderLine('X-Seen'));
    }
}
