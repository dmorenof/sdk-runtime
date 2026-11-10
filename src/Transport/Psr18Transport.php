<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Transport;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
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

    private ?RedirectPolicy $redirectPolicy = null;

    /** @var \Closure(int): void Receives the delay in milliseconds. */
    private \Closure $sleeper;

    /**
     * @param array<string, string> $defaultHeaders
     * @param ?\Closure(int): void $sleeper Replaces the default millisecond sleep (useful in tests).
     * @param ?RedirectPolicy $redirectPolicy Null follows up to 5 redirects with the safe defaults. The client must not follow redirects itself for the policy to apply.
     */
    public function __construct(
        protected ClientInterface $client,
        private array $defaultHeaders = [],
        ?RetryPolicy $retryPolicy = null,
        ?\Closure $sleeper = null,
        ?RedirectPolicy $redirectPolicy = null,
    ) {
        $this->retryPolicy = $retryPolicy;
        $this->redirectPolicy = $redirectPolicy;
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    public function setRetryPolicy(?RetryPolicy $policy): void
    {
        $this->retryPolicy = $policy;
    }

    public function setRedirectPolicy(?RedirectPolicy $policy): void
    {
        $this->redirectPolicy = $policy;
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
        $policy = $this->redirectPolicy ?? new RedirectPolicy();

        for ($hop = 0;; $hop++) {
            $response = $this->exchange($request);
            $location = $response->getHeaderLine('Location');
            if (!$policy->follows($response->getStatusCode()) || $location === '') {
                return $response;
            }
            if ($hop >= $policy->maxRedirects) {
                throw new RedirectException("Too many redirects (limit {$policy->maxRedirects}).", $response);
            }
            $next = $this->redirectRequest($request, $response, $location, $policy);
            if ($next === null) {
                return $response;
            }
            $response->getBody()->close();
            $request = $next;
        }
    }

    /** One hop: middleware, send with retries, middleware. */
    private function exchange(RequestInterface $request): ResponseInterface
    {
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

    /**
     * Builds the request for a redirect hop, or null when it cannot be followed safely
     * (the caller then returns the 3xx response untouched).
     */
    private function redirectRequest(RequestInterface $request, ResponseInterface $response, string $location, RedirectPolicy $policy): ?RequestInterface
    {
        try {
            $target = UriResolver::resolve($request->getUri(), new Uri($location));
        } catch (\InvalidArgumentException) {
            throw new RedirectException('Redirect Location is not a valid URI.', $response);
        }
        if (!in_array($target->getScheme(), ['http', 'https'], true) || $target->getHost() === '') {
            throw new RedirectException('Redirect target must be an absolute http(s) URL.', $response);
        }
        if ($target->getUserInfo() !== '') {
            throw new RedirectException('Redirect target must not embed credentials.', $response);
        }
        $from = $request->getUri();
        if ($from->getScheme() === 'https' && $target->getScheme() === 'http' && !$policy->allowDowngrade) {
            throw new RedirectException('Refusing to redirect from https to http.', $response);
        }
        $crossOrigin = !self::sameOrigin($from, $target);
        if ($crossOrigin && !$policy->followCrossOrigin) {
            return null;
        }

        $status = $response->getStatusCode();
        $method = strtoupper($request->getMethod());
        $keepsMethod = in_array($status, [307, 308], true);
        $toGet = !$keepsMethod && $method !== 'GET' && $method !== 'HEAD'
            && ($status === 303 || ($method === 'POST' && in_array($status, [301, 302], true)));
        if (!$toGet && $method !== 'GET' && $method !== 'HEAD'
            && !$request->getBody()->isSeekable() && $request->getBody()->getSize() !== 0) {
            return null; // the body cannot be resent
        }

        $next = $request;
        foreach (array_keys($request->getHeaders()) as $name) {
            if (strcasecmp((string) $name, 'Host') === 0) {
                continue;
            }
            if (($crossOrigin && !$policy->forwardsAcrossOrigins((string) $name))
                || ($toGet && in_array(strtolower((string) $name), ['content-type', 'content-length', 'transfer-encoding'], true))) {
                $next = $next->withoutHeader((string) $name);
            }
        }
        if ($toGet) {
            $next = $next->withMethod('GET')->withBody(\GuzzleHttp\Psr7\Utils::streamFor(''));
        }
        return $next->withUri($target);
    }

    private static function sameOrigin(\Psr\Http\Message\UriInterface $a, \Psr\Http\Message\UriInterface $b): bool
    {
        $port = static fn (\Psr\Http\Message\UriInterface $u): int => $u->getPort() ?? ($u->getScheme() === 'https' ? 443 : 80);
        return $a->getScheme() === $b->getScheme() && strtolower($a->getHost()) === strtolower($b->getHost()) && $port($a) === $port($b);
    }
}
