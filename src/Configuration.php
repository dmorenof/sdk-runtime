<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Every option of a client, set by name before connecting: `Client::connect($configuration)`.
 *
 * Only `baseUrl` is required, and a generated SDK supplies the API's own as the default. A client never changes
 * after it is created: to use other options, connect another client.
 */
class Configuration
{
    /** Where requests go. */
    public string $baseUrl = '';

    /** Any PSR-18 client. Default: Guzzle. Timeouts, proxies and TLS belong to it. */
    public ?\Psr\Http\Client\ClientInterface $httpClient = null;

    /**
     * Seconds a request may take in all, and seconds the connection may take to open, for the default HTTP client. Zero waits
     * without limit. They do not apply to a `$httpClient` you pass: its own timeouts are the ones that count.
     */
    public float $timeout = 30.0;
    public float $connectTimeout = 10.0;

    /**
     * Ask the server to compress its answers (`Accept-Encoding: gzip, deflate`) and decompress them before you see them. It saves
     * bandwidth and time on large answers. It applies to the default HTTP client: a `$httpClient` you pass decides for itself. An
     * answer read as a stream (events, downloads) is always asked for uncompressed. Off, nothing is asked for, and a compressed
     * answer that arrives anyway is still decoded.
     */
    public bool $compression = true;

    /** @var array<string, string> Sent on every request. */
    public array $headers = [];

    /** Secrets for the API's security schemes. */
    public ?Security\SecurityCredentials $securityCredentials = null;

    /** Replaces the whole transport (rare). Retries, redirects, middleware and streaming need the default one. */
    public ?Transport\TransportInterface $transport = null;

    /** @var list<Transport\HttpMiddlewareInterface> Hooks around every request, in order. */
    public array $middleware = [];

    /** Opt-in retries for transient failures. Null: never retry. */
    public ?Transport\RetryPolicy $retryPolicy = null;

    /** Opt-in following of redirects. Null: a 3xx is returned as it arrives. */
    public ?Transport\RedirectPolicy $redirectPolicy = null;

    /** @var array<string, string> Declared operation server URL => the URL to use instead (environments, mocks). */
    public array $serverOverrides = [];

    /** Which declared server operations use, by index or name. Null: the first. */
    public int|string|null $server = null;

    /** @var array<string, string> Values for the variables of templated servers. */
    public array $serverVariables = [];
}
