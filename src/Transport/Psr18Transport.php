<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Transport;

use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Common PSR-18/Guzzle adapter. Body bytes are already serialized according to the operation media type.
 * Interceptors run in registration order and may replace PSR-7 messages.
 */
final class Psr18Transport implements TransportInterface
{
    /** @var list<HttpMiddlewareInterface> */
    private array $middlewares = [];

    private ?RetryPolicy $retryPolicy = null;

    /** @var \Closure(int): void Receives the delay in milliseconds. */
    private \Closure $sleeper;

    /**
     * @param array<string, string> $defaultHeaders
     * @param ?\Closure(int): void $sleeper Replaces the default millisecond sleep (useful in tests).
     */
    public function __construct(
        protected ClientInterface $client,
        private array $defaultHeaders = [],
        ?RetryPolicy $retryPolicy = null,
        ?\Closure $sleeper = null,
    ) {
        $this->retryPolicy = $retryPolicy;
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    public function setRetryPolicy(?RetryPolicy $policy): void
    {
        $this->retryPolicy = $policy;
    }

    public function addMiddleware(HttpMiddlewareInterface $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    /** @param array<string, string> $headers */
    public function send(string $method, string $url, array $headers, string|StreamInterface|null $body): HttpResponse
    {
        return HttpResponse::fromPsr($this->sendResponse($method, $url, $headers, $body));
    }

    /** @param array<string, string> $headers */
    public function sendResponse(string $method, string $url, array $headers, string|StreamInterface|null $body): ResponseInterface
    {
        $request = new Request($method, $url, \Dmorenof\SdkRuntime\CookieHeader::merge($this->defaultHeaders, $headers), $body ?? '');

        foreach ($this->middlewares as $middleware) {
            $request = $middleware->beforeRequest($request);
        }

        // Only replay when the policy allows the method and the body can be re-read from its start.
        $policy = $this->retryPolicy;
        $retryable = $policy !== null
            && $policy->allows($request->getMethod())
            && ($request->getBody()->isSeekable() || $request->getBody()->getSize() === 0);

        for ($attempt = 1;; $attempt++) {
            // Middleware may inspect or replace a body. Send seekable bodies from their start,
            // including multipart streams whose source files were consumed by a previous send.
            if ($request->getBody()->isSeekable()) {
                $request->getBody()->rewind();
            }

            $canRetry = $policy !== null && $retryable && $attempt < $policy->maxAttempts;
            try {
                // PSR-18 returns non-2xx responses without throwing for status alone.
                // Endpoint-specific code retains control of expected status checking.
                $response = $this->client->sendRequest($request);
            } catch (NetworkExceptionInterface $e) {
                if ($policy === null || !$canRetry || !$policy->retryNetworkErrors) {
                    throw $e;
                }
                ($this->sleeper)($policy->delayMs($attempt));
                continue;
            }

            if ($policy !== null && $canRetry && $policy->retriesStatus($response->getStatusCode())) {
                $delay = $policy->delayMs($attempt, $response);
                $response->getBody()->close();
                ($this->sleeper)($delay);
                continue;
            }
            break;
        }

        foreach ($this->middlewares as $middleware) {
            $response = $middleware->afterResponse($request, $response);
        }

        if ($response->getBody()->isSeekable()) {
            $response->getBody()->rewind();
        }
        return $response;
    }
}
