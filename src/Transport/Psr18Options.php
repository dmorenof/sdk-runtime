<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Transport;

/**
 * What the PSR-18 transport does around each request: headers it adds, middleware, retries and redirects.
 * Without options it sends each request once, adds nothing and returns a 3xx as it arrives.
 */
final readonly class Psr18Options
{
    /**
     * @param array<string, string> $defaultHeaders Sent with every request.
     * @param list<HttpMiddlewareInterface> $middleware Run in order around each request.
     * @param ?\Closure(int): void $sleeper Replaces the default millisecond sleep (useful in tests).
     */
    public function __construct(
        public array $defaultHeaders = [],
        public array $middleware = [],
        public ?RetryPolicy $retryPolicy = null,
        public ?RedirectPolicy $redirectPolicy = null,
        public ?\Closure $sleeper = null,
    ) {}
}
