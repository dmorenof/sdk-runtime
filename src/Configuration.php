<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/** Shared connection options; generated SDKs only supply their default base URL. */
class Configuration
{
    public string $baseUrl = '';

    public ?\Psr\Http\Client\ClientInterface $httpClient = null;

    /** @var array<string, string> */
    public array $headers = [];

    public ?Security\SecurityCredentials $securityCredentials = null;
    public ?Transport\TransportInterface $transport = null;

    /** @var array<string, string> Declared operation server URL => environment base URL. */
    public array $serverOverrides = [];

    /** @var list<Transport\HttpMiddlewareInterface> */
    public array $middleware = [];
}
