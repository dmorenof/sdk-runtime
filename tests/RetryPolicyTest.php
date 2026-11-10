<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiException;
use Dmorenof\SdkRuntime\Tests\Fixtures\TestClient;
use Dmorenof\SdkRuntime\Transport\Psr18Transport;
use Dmorenof\SdkRuntime\Transport\RetryPolicy;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RetryPolicyTest extends TestCase
{
    /** @param list<ResponseInterface|\Throwable> $script */
    private function http(array $script): object
    {
        return new class ($script) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];

            /** @param list<ResponseInterface|\Throwable> $script */
            public function __construct(private array $script) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                $next = array_shift($this->script) ?? throw new \LogicException('Script exhausted.');
                if ($next instanceof \Throwable) {
                    throw $next;
                }
                return $next;
            }
        };
    }

    private static function networkError(): \Throwable
    {
        return new class ('connection reset') extends \RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                return new \GuzzleHttp\Psr7\Request('GET', 'https://api.example.com');
            }
        };
    }

    /** @return array{Psr18Transport, list<int>} */
    private function transport(ClientInterface $http, ?RetryPolicy $policy, array &$sleeps): array
    {
        $sleeps = [];
        $transport = new Psr18Transport($http, [], $policy, static function (int $ms) use (&$sleeps): void {
            $sleeps[] = $ms;
        });
        return [$transport, $sleeps];
    }

    public function testRetriesTransientStatusThenSucceeds(): void
    {
        $http = $this->http([new Response(503), new Response(429), new Response(200, [], 'ok')]);
        [$transport] = $this->transport($http, new RetryPolicy(baseDelayMs: 0, maxDelayMs: 0), $sleeps);

        $response = $transport->sendResponse('GET', 'https://api.example.com/x', [], null);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(3, $http->requests);
        self::assertCount(2, $sleeps);
    }

    public function testReturnsLastResponseWhenAttemptsAreExhausted(): void
    {
        $http = $this->http([new Response(503), new Response(503)]);
        [$transport] = $this->transport($http, new RetryPolicy(maxAttempts: 2, baseDelayMs: 0, maxDelayMs: 0), $sleeps);

        self::assertSame(503, $transport->sendResponse('GET', 'https://api.example.com/x', [], null)->getStatusCode());
        self::assertCount(2, $http->requests);
    }

    public function testHonorsRetryAfterSecondsCappedByMaxDelay(): void
    {
        $http = $this->http([new Response(429, ['Retry-After' => '3']), new Response(429, ['Retry-After' => '999']), new Response(200)]);
        [$transport] = $this->transport($http, new RetryPolicy(maxDelayMs: 5000), $sleeps);

        $transport->sendResponse('GET', 'https://api.example.com/x', [], null);

        self::assertSame([3000, 5000], $sleeps);
    }

    public function testRetryAfterHttpDate(): void
    {
        $policy = new RetryPolicy(maxDelayMs: 60_000);
        $now = 1_700_000_000;
        $response = new Response(503, ['Retry-After' => gmdate('D, d M Y H:i:s', $now + 7) . ' GMT']);

        self::assertSame(7000, $policy->delayMs(1, $response, $now));
        self::assertSame(0, $policy->delayMs(1, new Response(503, ['Retry-After' => gmdate('D, d M Y H:i:s', $now - 60) . ' GMT']), $now));
    }

    public function testBackoffGrowsExponentiallyWithinJitterBounds(): void
    {
        $policy = new RetryPolicy(baseDelayMs: 100, maxDelayMs: 1000);

        foreach ([1 => [50, 100], 2 => [100, 200], 3 => [200, 400], 6 => [500, 1000]] as $retry => [$min, $max]) {
            for ($i = 0; $i < 20; $i++) {
                $delay = $policy->delayMs($retry);
                self::assertGreaterThanOrEqual($min, $delay);
                self::assertLessThanOrEqual($max, $delay);
            }
        }
    }

    public function testNonIdempotentMethodsAreNotReplayedUnlessEnabled(): void
    {
        $http = $this->http([new Response(503)]);
        [$transport] = $this->transport($http, new RetryPolicy(baseDelayMs: 0, maxDelayMs: 0), $sleeps);
        self::assertSame(503, $transport->sendResponse('POST', 'https://api.example.com/x', [], '{}')->getStatusCode());
        self::assertCount(1, $http->requests);

        $http = $this->http([new Response(503), new Response(201)]);
        [$transport] = $this->transport($http, new RetryPolicy(methods: ['POST'], baseDelayMs: 0, maxDelayMs: 0), $sleeps);
        self::assertSame(201, $transport->sendResponse('POST', 'https://api.example.com/x', [], '{"a":1}')->getStatusCode());
        self::assertCount(2, $http->requests);
        self::assertSame('{"a":1}', (string) $http->requests[1]->getBody(), 'body is resent from its start');
    }

    public function testNoPolicyMeansSingleAttempt(): void
    {
        $http = $this->http([new Response(503)]);
        [$transport] = $this->transport($http, null, $sleeps);

        self::assertSame(503, $transport->sendResponse('GET', 'https://api.example.com/x', [], null)->getStatusCode());
        self::assertSame([], $sleeps);
    }

    public function testRetriesNetworkErrorsAndRethrowsWhenExhausted(): void
    {
        $http = $this->http([self::networkError(), new Response(200)]);
        [$transport] = $this->transport($http, new RetryPolicy(baseDelayMs: 0, maxDelayMs: 0), $sleeps);
        self::assertSame(200, $transport->sendResponse('GET', 'https://api.example.com/x', [], null)->getStatusCode());

        $http = $this->http([self::networkError(), self::networkError()]);
        [$transport] = $this->transport($http, new RetryPolicy(maxAttempts: 2, baseDelayMs: 0, maxDelayMs: 0), $sleeps);
        $this->expectException(NetworkExceptionInterface::class);
        $transport->sendResponse('GET', 'https://api.example.com/x', [], null);
    }

    public function testNetworkErrorRetryCanBeDisabled(): void
    {
        $http = $this->http([self::networkError(), new Response(200)]);
        [$transport] = $this->transport($http, new RetryPolicy(retryNetworkErrors: false), $sleeps);

        $this->expectException(NetworkExceptionInterface::class);
        $transport->sendResponse('GET', 'https://api.example.com/x', [], null);
    }

    public function testNonSeekableBodyIsNeverReplayed(): void
    {
        $stream = Utils::streamFor(new class {
            public function __toString(): string
            {
                return 'x';
            }
        });
        $nonSeekable = new \GuzzleHttp\Psr7\NoSeekStream(Utils::streamFor('payload'));
        $http = $this->http([new Response(503)]);
        [$transport] = $this->transport($http, new RetryPolicy(methods: ['PUT'], baseDelayMs: 0, maxDelayMs: 0), $sleeps);

        self::assertSame(503, $transport->sendResponse('PUT', 'https://api.example.com/x', [], $nonSeekable)->getStatusCode());
        self::assertCount(1, $http->requests);
        self::assertNotNull($stream);
    }

    public function testRejectsInvalidLimits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RetryPolicy(maxAttempts: 0);
    }

    public function testApiClientOptsInThroughWithRetryPolicy(): void
    {
        $http = $this->http([new Response(503), new Response(200, [], '')]);
        $client = TestClient::connect('https://api.example.com', httpClient: $http);
        $client->withRetryPolicy(new RetryPolicy(baseDelayMs: 0, maxDelayMs: 0));

        $client->ping();

        self::assertCount(2, $http->requests);
    }

    public function testApiClientWithoutPolicyStillFailsOnFirst503(): void
    {
        $http = $this->http([new Response(503, [], '{}')]);
        $client = TestClient::connect('https://api.example.com', httpClient: $http);

        $this->expectException(ApiException::class);
        try {
            $client->ping();
        } finally {
            self::assertCount(1, $http->requests);
        }
    }
}
