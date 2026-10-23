<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Transport;

use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Common PSR-18/Guzzle adapter. Body is already serialized to JSON.
 * Interceptors run in registration order and may replace PSR-7 messages.
 */
final class Psr18Transport implements TransportInterface
{
    /** @var list<HttpMiddlewareInterface> */
    private array $middlewares = [];

    /** @param array<string, string> $defaultHeaders */
    public function __construct(
        protected ClientInterface $client,
        private array $defaultHeaders = [],
    ) {}

    public function addMiddleware(HttpMiddlewareInterface $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    /** @param array<string, string> $headers */
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $request = new Request($method, $url, array_replace($this->defaultHeaders, $headers), $body ?? '');

        foreach ($this->middlewares as $middleware) {
            $request = $middleware->beforeRequest($request);
        }

        // PSR-18 returns non-2xx responses without throwing for status alone.
        // Endpoint-specific code retains control of expected status checking.
        $response = $this->client->sendRequest($request);

        foreach ($this->middlewares as $middleware) {
            $response = $middleware->afterResponse($request, $response);
        }

        $resultHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $resultHeaders[(string) $name] = implode(', ', $values);
        }
        return new HttpResponse($response->getStatusCode(), (string)$response->getBody(), $resultHeaders);
    }
}