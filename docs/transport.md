# Transport and servers

How requests are sent, and where.

## The HTTP client

The runtime talks to any [PSR-18](https://www.php-fig.org/psr/psr-18/) client. If you give none, it creates a Guzzle client that does not throw on 4xx/5xx (the runtime decides what is an error).

Everything about the connection itself belongs to that client: timeouts, proxies, TLS, certificates, HTTP/2, caching.

```php
$config = new Configuration();
$config->httpClient = new \GuzzleHttp\Client([
    'timeout'         => 10,        // total seconds
    'connect_timeout' => 3,
    'proxy'           => 'http://proxy.internal:3128',
    'verify'          => '/etc/ssl/corp-ca.pem',
]);
```

Any other PSR-18 client works (Symfony HttpClient, a mock for tests…). The runtime never reaches around it.

> Keep `http_errors` off if you build your own Guzzle client. The runtime reads status codes itself.

## Testing without a network

Pass a fake PSR-18 client:

```php
$http = new class implements \Psr\Http\Client\ClientInterface {
    public array $sent = [];
    public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $this->sent[] = $request;
        return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"id":1,"name":"Ada"}');
    }
};

$client = Client::connect('https://api.example.com', $http);
```

## Middleware

A middleware sees every request before it leaves and every response before the SDK decodes it:

```php
use Dmorenof\SdkRuntime\Transport\HttpMiddlewareInterface;
use Psr\Http\Message\{RequestInterface, ResponseInterface};

final class RequestId implements HttpMiddlewareInterface
{
    public function beforeRequest(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('X-Request-Id', bin2hex(random_bytes(8)));
    }

    public function afterResponse(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $response;
    }
}

$config->middleware = [new RequestId()];
// or, on a client:  $client->withMiddleware(new RequestId());
```

Middleware must not read a streaming response body (`jsonl`, `sse`): it would consume it. If you prefer, a Guzzle `HandlerStack` on your own `httpClient` does the same job.

## Retries (opt-in)

Off by default. Turn them on for transient failures:

```php
use Dmorenof\SdkRuntime\Transport\RetryPolicy;

$client->withRetryPolicy(new RetryPolicy());                       // sensible defaults
$client->withRetryPolicy(new RetryPolicy(maxAttempts: 5, baseDelayMs: 500));
$client->withRetryPolicy(null);                                    // off again
```

Defaults: up to **3 attempts in total**; retries statuses **429, 502, 503, 504** and network errors; only for **GET, HEAD, OPTIONS, PUT, DELETE** (add `POST` or `PATCH` to `methods:` only if the API makes them safe to repeat); waits 200 ms doubling up to 10 s, with jitter.

- A `Retry-After` header (seconds or a date) is respected, but never longer than `maxDelayMs`. A server asking for 120 s is waited for 10 s by default; raise `maxDelayMs` if you want to honor long waits.
- A request whose body cannot be rewound (a one-shot stream) is never retried.
- When attempts run out, you get the last response (a normal `ApiException` for an error status) or the last network exception.

## Redirects (opt-in)

By default a `3xx` is **not** followed: you get it as it arrives (an `ApiException` unless the operation declares it).

```php
use Dmorenof\SdkRuntime\Transport\RedirectPolicy;

$client->withRedirectPolicy(new RedirectPolicy());                          // follow up to 5
$client->withRedirectPolicy(new RedirectPolicy(maxRedirects: 2, followCrossOrigin: false));
$client->withRedirectPolicy(null);                                          // back to not following
```

Safety rules that always apply when following: a redirect to another origin drops every request header except `Accept`, `Accept-Encoding`, `Accept-Language`, `User-Agent` and `Content-Type` (credentials never leak, even if a middleware adds them again); `https` → `http` is refused with `RedirectException`; `303` turns any method except GET/HEAD into GET; `301`/`302` turn POST into GET; `307`/`308` keep method and body (if the body can be sent again).

Both policies belong to the **transport**, which a client shares with every resource created from it. Setting one on a client applies to that client and its resources.

## Servers

### The base URL

`Client::connect($baseUrl, …)` (or `Configuration::$baseUrl`) is where requests go. A generated SDK defaults it to the first server the API declares.

### Operations on another server

Some operations live on a different host (for example uploads). The SDK records that. To point such servers somewhere else (staging, a mock), map the declared URL to yours:

```php
$config->serverOverrides = [
    'https://uploads.example.com' => 'https://uploads.staging.example.com',
];
```

### Choosing among declared servers, and variables

If an API declares several servers, or servers with variables (`https://{region}.api.example.com/{version}`), an operation can offer a choice:

```php
$client->useServer('sandbox');                      // by name or index; null = the first one
$client->withServerVariables(['region' => 'us']);   // checked against the API's allowed values
```

Variables the chosen server does not use are ignored, so one set of values serves every operation. Values that would change the URL's structure (`/` in a host, `?`, `#`, `@`, spaces) are rejected.

For **root** servers (the base URL itself), the SDK exposes a builder:

```php
$url = Client::serverUrl('production', ['region' => 'us']);   // https://us.api.example.com/v1
$client = Client::connect($url);
```

### Relative servers

A server such as `/v2` or `../v2` is resolved against the base URL you configured, as if the base URL were a directory: `/v2` replaces the path; `v2` and `../v2` start from it. A relative **root** server has no origin of its own, so the SDK leaves `Configuration::$baseUrl` empty and you must set it.

## Writing your own transport

Implement `TransportInterface::send($method, $url, $headers, $body): HttpResponse` and pass it as `transport:`. Retries, redirects, middleware and streaming belong to `Psr18Transport` and are not available through a custom transport. Wrapping the PSR-18 client is almost always the better choice.

## Methods that change the client

`withMiddleware`, `withRetryPolicy`, `withRedirectPolicy`, `useServer` and `withServerVariables` **modify the client you call them on and return it**; they do not return a copy. Chain them for convenience, but do not expect the original to stay untouched. Server choices (`useServer`, `withServerVariables`) are copied to resources you create afterwards; retry, redirect and middleware settings live in the shared transport, so they reach every resource of that client, including ones created earlier.
