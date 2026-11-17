# Migrating from 1.0.0 to 2.0.0

2.0.0 changes how a client is created and configured and how OAuth2 providers are built. The HTTP behavior, models, validation and error types are unchanged. Generated SDKs need sdk-generator 2.0.0; regenerate them.

## Creating and configuring a client

| 1.0.0 | 2.0.0 |
| --- | --- |
| `Client::connect($url)` | unchanged |
| `Client::connect($url, $http, $headers, $credentials, $transport, $overrides)` | set the same names on a `Configuration`, then `Client::connect($configuration)` |
| `$client->withMiddleware($m)` | `$configuration->middleware = [$m]` |
| `$client->withRetryPolicy($p)` | `$configuration->retryPolicy = $p` |
| `$client->withRedirectPolicy($p)` | `$configuration->redirectPolicy = $p` |
| `$client->useServer('sandbox')` | `$configuration->server = 'sandbox'` |
| `$client->withServerVariables([...])` | `$configuration->serverVariables = [...]` |

```php
// 1.0.0
$client = Client::connect('https://api.example.com', $http, securityCredentials: $credentials)
    ->withRetryPolicy(new RetryPolicy());

// 2.0.0
$config = new Configuration();
$config->baseUrl = 'https://api.example.com';
$config->httpClient = $http;
$config->securityCredentials = $credentials;
$config->retryPolicy = new RetryPolicy();
$client = Client::connect($config);
```

A client now takes a snapshot of its configuration: changing the object afterwards changes nothing. To switch region or turn retries off, connect another client. With the generated static facade, `Users::configure($config)` works as before.

`middleware`, `retryPolicy` and `redirectPolicy` need the default PSR-18 transport: combining them with a custom `transport` throws `LogicException` when you connect.

## OAuth2

```php
// 1.0.0
new OAuth2ClientCredentials($tokenUrl, $id, $secret, $http, scopes: ['read'], credentialsInBody: true);

// 2.0.0
new OAuth2ClientCredentials(
    new OAuth2Endpoints($tokenUrl),
    new OAuth2Client($id, $secret, secretInBody: true),
    new OAuth2Options(scopes: ['read'], http: $http),
);
```

For the authorization code flow, `authorizationUrl` and `issuer` go in `OAuth2Endpoints`, `redirectUri` in `OAuth2Client`, and `store`, `subject`, `leeway`, `clock` in `OAuth2Options`. The `requestLifetime` option is gone (fixed at 10 minutes). See [Authentication](authentication.md).

## Errors

`ApiException` is built as `new ApiException($response, $operation, $payload, $previous)`, and `ApiException::fromResponse()` no longer exists. Catching `ApiException` and reading `$response`, `$payload` and `$error` is unchanged.

## Writing a transport or a generator

- `new Psr18Transport($client, new Psr18Options(...))` replaces `new Psr18Transport($client, $headers, $retryPolicy, $sleeper, $redirectPolicy)`, and `setRetryPolicy()`, `setRedirectPolicy()` and `addMiddleware()` are gone: everything is passed in `Psr18Options`. `sendResponse(RequestInterface $request, bool $stream = false)` replaces the method/URL/headers/body form.
- `Operation` is built only with `Operation::fromArray($spec)`.
- `executeArguments`, `executeBody` and `executeOperation` have new signatures: [Operation metadata](operation-metadata.md#executing).
