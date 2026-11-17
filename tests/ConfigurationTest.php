<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Tests\Fixtures\TestClient;
use Dmorenof\SdkRuntime\Tests\Support\Cfg;
use Dmorenof\SdkRuntime\Transport\HttpMiddlewareInterface;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A client is built from its Configuration and never changes afterwards. */
final class ConfigurationTest extends TestCase
{
    private function http(): object
    {
        return new class implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                return new Response(200, ['Content-Type' => 'application/json'], '{"id":1,"name":"Ada"}');
            }
        };
    }

    public function testAStringIsShorthandForABaseUrlWithDefaults(): void
    {
        $client = TestClient::connect('https://api.example.com');

        self::assertInstanceOf(TestClient::class, $client);
    }

    public function testAnEmptyBaseUrlIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TestClient::connect(Cfg::make(''));
    }

    public function testOptionsThatNeedThePsr18TransportAreRejectedWithACustomOne(): void
    {
        $middleware = new class implements HttpMiddlewareInterface {
            public function beforeRequest(RequestInterface $request): RequestInterface { return $request; }
            public function afterResponse(RequestInterface $request, ResponseInterface $response): ResponseInterface { return $response; }
        };

        $this->expectException(\LogicException::class);
        TestClient::connect(Cfg::make('https://api.example.com', transport: new FakeTransport(new HttpResponse(200, '')), middleware: [$middleware]));
    }

    public function testAResourceSharesTheTransportAndRunsMiddlewareOnce(): void
    {
        $http = $this->http();
        $calls = new \ArrayObject();
        $middleware = new class ($calls) implements HttpMiddlewareInterface {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private readonly \ArrayObject $calls) {}

            public function beforeRequest(RequestInterface $request): RequestInterface
            {
                $this->calls[] = $request->getMethod();
                return $request;
            }

            public function afterResponse(RequestInterface $request, ResponseInterface $response): ResponseInterface { return $response; }
        };
        $client = TestClient::connect(Cfg::make('https://api.example.com', httpClient: $http, headers: ['X-App' => 'docs'], middleware: [$middleware]));

        $client->resource(TestClient::class)->ping();

        self::assertCount(1, $calls);
        self::assertSame('docs', $http->requests[0]->getHeaderLine('X-App'));
    }

    public function testChangingTheConfigurationAfterConnectingChangesNothing(): void
    {
        $http = $this->http();
        $configuration = Cfg::make('https://api.example.com', httpClient: $http);
        $client = TestClient::connect($configuration);

        $configuration->baseUrl = 'https://elsewhere.example.com';
        $client->ping();

        self::assertSame('api.example.com', $http->requests[0]->getUri()->getHost());
    }
}
