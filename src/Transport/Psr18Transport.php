<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Transport;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Common PSR-18/Guzzle adapter. Body bytes are already serialized according to the operation media type.
 * Interceptors run in registration order and may replace PSR-7 messages.
 *
 * Each call follows the same layers: redirects (RedirectPolicy) wrap one exchange per hop; an exchange runs the
 * middleware around a send with retries (RetryPolicy); a send hands the request to the PSR-18 client.
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
     * @param ?RedirectPolicy $redirectPolicy Null never follows redirects: the 3xx response is returned. Guzzle's PSR-18 sendRequest() never follows them either, so this policy decides.
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

    /**
     * @param array<string, string> $headers
     * @param bool $stream Do not buffer the response body: it is read incrementally from the returned stream.
     *                     Honored by Guzzle clients (which then use their stream handler); other PSR-18 clients
     *                     decide for themselves whether they buffer.
     */
    public function sendResponse(string $method, string $url, array $headers, string|StreamInterface|null $body, bool $stream = false): ResponseInterface
    {
        $request = new Request($method, $url, \Dmorenof\SdkRuntime\CookieHeader::merge($this->defaultHeaders, $headers), $body ?? '');
        $policy = $this->redirectPolicy ?? RedirectPolicy::none();
        $origin = $request->getUri();

        for ($hop = 0;; $hop++) {
            $response = $this->exchange($request, $stream, $origin, $policy);
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

    /**
     * One hop: middleware, a send with retries, middleware. A request that has left the origin of the original
     * call is stripped of credentials after the middleware ran, so a middleware cannot add them back.
     */
    private function exchange(RequestInterface $request, bool $stream, UriInterface $origin, RedirectPolicy $redirects): ResponseInterface
    {
        foreach ($this->middlewares as $middleware) {
            $request = $middleware->beforeRequest($request);
        }
        if (!self::sameOrigin($origin, $request->getUri())) {
            $request = $redirects->withoutCredentials($request);
        }

        $response = $this->sendWithRetries($request, $stream);

        foreach ($this->middlewares as $middleware) {
            $response = $middleware->afterResponse($request, $response);
        }
        if ($response->getBody()->isSeekable()) {
            $response->getBody()->rewind();
        }
        return $response;
    }

    /** Sends once, or again after a transient failure when the policy allows the method and the body can be resent. */
    private function sendWithRetries(RequestInterface $request, bool $stream): ResponseInterface
    {
        $policy = $this->retryPolicy ?? new RetryPolicy(maxAttempts: 1);
        $maxAttempts = $policy->allows($request->getMethod()) && self::replayable($request) ? $policy->maxAttempts : 1;

        for ($attempt = 1;; $attempt++) {
            // Middleware may inspect or replace a body. Send seekable bodies from their start,
            // including multipart streams whose source files were consumed by a previous send.
            if ($request->getBody()->isSeekable()) {
                $request->getBody()->rewind();
            }
            $canRetry = $attempt < $maxAttempts;
            try {
                // PSR-18 returns non-2xx responses without throwing for status alone.
                // Endpoint-specific code retains control of expected status checking.
                $response = $this->dispatch($request, $stream);
            } catch (NetworkExceptionInterface $e) {
                if (!$canRetry || !$policy->retryNetworkErrors) {
                    throw $e;
                }
                ($this->sleeper)($policy->delayMs($attempt));
                continue;
            }
            if (!$canRetry || !$policy->retriesStatus($response->getStatusCode())) {
                return $response;
            }
            $delay = $policy->delayMs($attempt, $response);
            $response->getBody()->close();
            ($this->sleeper)($delay);
        }
    }

    private function dispatch(RequestInterface $request, bool $stream): ResponseInterface
    {
        if ($stream && $this->client instanceof \GuzzleHttp\ClientInterface) {
            // PSR-18 sendRequest() cannot carry options, and without "stream" Guzzle waits for the whole body.
            return $this->client->send($request, ['stream' => true, 'http_errors' => false, 'allow_redirects' => false]);
        }
        return $this->client->sendRequest($request);
    }

    /**
     * Builds the request for a redirect hop, or null when it cannot be followed safely
     * (the caller then returns the 3xx response untouched).
     */
    private function redirectRequest(RequestInterface $request, ResponseInterface $response, string $location, RedirectPolicy $policy): ?RequestInterface
    {
        $target = self::redirectTarget($request->getUri(), $response, $location, $policy);
        if (!$policy->followCrossOrigin && !self::sameOrigin($request->getUri(), $target)) {
            return null;
        }

        $method = $request->getMethod();
        $nextMethod = $policy->methodAfter($method, $response->getStatusCode());
        $changesMethod = $nextMethod !== $method;
        if (!$changesMethod && !in_array(strtoupper($method), ['GET', 'HEAD'], true) && !self::replayable($request)) {
            return null; // the body cannot be resent
        }

        $redirected = $request;
        if ($changesMethod) {
            foreach (['Content-Type', 'Content-Length', 'Transfer-Encoding'] as $header) {
                $redirected = $redirected->withoutHeader($header);
            }
            $redirected = $redirected->withMethod($nextMethod)->withBody(Utils::streamFor(''));
        }
        return $redirected->withUri($target);
    }

    /** Resolves a Location against the current URI and rejects targets a client must never follow. */
    private static function redirectTarget(UriInterface $from, ResponseInterface $response, string $location, RedirectPolicy $policy): UriInterface
    {
        try {
            $target = UriResolver::resolve($from, new Uri($location));
        } catch (\InvalidArgumentException) {
            throw new RedirectException('Redirect Location is not a valid URI.', $response);
        }
        if (!in_array($target->getScheme(), ['http', 'https'], true) || $target->getHost() === '') {
            throw new RedirectException('Redirect target must be an absolute http(s) URL.', $response);
        }
        if ($target->getUserInfo() !== '') {
            throw new RedirectException('Redirect target must not embed credentials.', $response);
        }
        if ($from->getScheme() === 'https' && $target->getScheme() === 'http' && !$policy->allowDowngrade) {
            throw new RedirectException('Refusing to redirect from https to http.', $response);
        }
        return $target;
    }

    /** The body can be read again from its start (or is empty), so the request can be sent a second time. */
    private static function replayable(RequestInterface $request): bool
    {
        $body = $request->getBody();
        return $body->isSeekable() || $body->getSize() === 0;
    }

    /** Same scheme, host and port; psr7 already leaves out a scheme's default port. */
    private static function sameOrigin(UriInterface $a, UriInterface $b): bool
    {
        return $a->getScheme() === $b->getScheme() && strtolower($a->getHost()) === strtolower($b->getHost()) && $a->getPort() === $b->getPort();
    }
}
