<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests\Support;

use Dmorenof\SdkRuntime\Transport\Psr18Options;
use Dmorenof\SdkRuntime\Transport\Psr18Transport;
use Dmorenof\SdkRuntime\Transport\RedirectPolicy;
use Dmorenof\SdkRuntime\Transport\RetryPolicy;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/** Test helpers for the PSR-18 transport: flat arguments in, so each test names only what it varies. */
final class Wire
{
    /**
     * @param array<string, string> $defaultHeaders
     * @param list<\Dmorenof\SdkRuntime\Transport\HttpMiddlewareInterface> $middleware
     * @param ?\Closure(int): void $sleeper
     */
    public static function transport(
        ClientInterface $client,
        array $defaultHeaders = [],
        ?RetryPolicy $retryPolicy = null,
        ?\Closure $sleeper = null,
        ?RedirectPolicy $redirectPolicy = null,
        array $middleware = [],
        bool $compression = false,
    ): Psr18Transport {
        return new Psr18Transport($client, new Psr18Options($defaultHeaders, $middleware, $retryPolicy, $redirectPolicy, $sleeper, $compression));
    }

    /** @param array<string, string> $headers */
    public static function send(Psr18Transport $transport, string $method, string $url, array $headers = [], string|StreamInterface|null $body = null, bool $stream = false): ResponseInterface
    {
        return $transport->sendResponse(new Request($method, $url, $headers, $body ?? ''), $stream);
    }
}
