# API reference

Every public class, grouped by purpose. Signatures are abbreviated; the authoritative list is [`tests/Fixtures/public-api.txt`](../tests/Fixtures/public-api.txt), which a test keeps in sync with the code. What may change between versions: [Public API and versioning](PUBLIC_API.md).

Namespace: `Dmorenof\SdkRuntime` unless shown.

## Client

### `ApiClient` (abstract; generated SDKs extend it)

| Member | Purpose |
| --- | --- |
| `static connect($baseUrl, $httpClient = null, $headers = [], $securityCredentials = null, $transport = null, $serverOverrides = [])` | Create a client |
| `resource(string $type): static` | A client for one resource class, sharing transport, credentials and server choice |
| `lastResponse(): ?ResponseInfo` | Status and headers of the last response of *this* instance |
| `withMiddleware($m)`, `withRetryPolicy($p)`, `withRedirectPolicy($p)` | Configure the shared transport (modify and return the client) |
| `useServer($nameOrIndex)`, `withServerVariables($values)` | Choose among declared servers (modify and return the client) |
| `static serverUrl($server = 0, $variables = [])` | Base URL for a declared root server |
| `const SECURITY_SCHEMES`, `const SERVERS` | Declared by the SDK. See [Operation metadata](operation-metadata.md) |
| `executeArguments(...)`, `executeBody(...)`, `executeOperation(...)` (protected) | Run an operation. For generators |

### `Configuration`

Plain properties: `baseUrl`, `httpClient`, `headers`, `securityCredentials`, `transport`, `serverOverrides`, `middleware`.

### `ApiException extends RuntimeException`

`$response` (`HttpResponse`), `$payload` (decoded error), `$error` (decoded error when it is a model), `getCode()` = HTTP status.

## Models

| Class | Purpose |
| --- | --- |
| `DTO` | Base of every model. `new X(?object $json)`, `static fromValue(object)`, `validate()`, `validateForRequest()`, `validateForResponse()`, `jsonSerialize()`, `jsonSerializeForRequest()`, `jsonSerializeForResponse()`, `additionalProperties()`, `getAdditionalProperty($name)`, `setAdditionalProperty($name, $value)`, `__toString()` |
| `Request`, `Response` | `DTO` variants that serialize only the declared direction |
| `TypedArray` | List of one type. `new X(array $items)`, `append()`, array access, `count()`, iteration, `getExpectedType()` |
| `Map` | String-keyed dictionary of one type. Array access, `count()`, iteration, `getArrayCopy()`, `validate()` |
| `AnyOf`, `OneOf` | Composition wrappers. `static fromValue($value)`, `matchedVariants()` |

## Security — `Security\*`

| Class | Purpose |
| --- | --- |
| `SecurityCredentials` | Scheme name → secret. `new SecurityCredentials(array)`, `forScheme($name)` |
| `BasicAuthCredentials` | `new BasicAuthCredentials($username, $password)` |
| `TokenProviderInterface` | `accessToken(): string`, `invalidate(): void` |
| `OAuth2ClientCredentials` | Machine-to-machine tokens: cached, renewed, replayed after 401. Also `forget()` |
| `OAuth2AuthorizationCode` | User sign-in with PKCE: `start()`, `complete($request, $callback)`, `isAuthorized()`, `forget()` |
| `AuthorizationRequest` | State of a started flow: `$url`, `$state`, `$codeVerifier`, `$expiresAt`; `toArray()`, `fromArray()` |
| `OAuth2Token` | `$accessToken`, `$expiresAt`, `$refreshToken`, `isValid($now, $leeway)` |
| `TokenStoreInterface`, `InMemoryTokenStore` | `get`, `set`, `delete` by key |
| `OAuth2Exception`, `ReauthorizationRequiredException` | Token endpoint failure; the user must sign in again. `isRejection()` |

## Transport — `Transport\*`

| Class | Purpose |
| --- | --- |
| `TransportInterface` | `send($method, $url, $headers, $body): HttpResponse` |
| `Psr18Transport` | The default transport over any PSR-18 client; adds middleware, retries, redirects and streaming |
| `HttpMiddlewareInterface` | `beforeRequest($request)`, `afterResponse($request, $response)` |
| `RetryPolicy` | `maxAttempts`, `statuses`, `methods`, `baseDelayMs`, `maxDelayMs`, `retryNetworkErrors` |
| `RedirectPolicy` | `maxRedirects`, `statuses`, `allowDowngrade`, `followCrossOrigin`, `crossOriginHeaders`; `RedirectPolicy::none()` |
| `RedirectException` | A redirect was refused. `$response` |
| `HttpResponse` | Buffered response: `$status`, `$body`, `$headers`, `header($name)`, `fromPsr()` |
| `ResponseInfo` | `$status`, `$headers`, `$operation` |
| `ResponseHeaders` | `get`, `first`, `values`, `has`, `names`, `cookies` |

## Streaming — `Streaming\*`

`ServerSentEvent`: `$event`, `$data`, `$id`, `$retry`, `$item`.

## Operation

`Operation`: immutable description of one HTTP call. `Operation::fromArray($spec)`, `DEFAULTS`, `STREAMING_ENCODINGS`; read-only properties for every key in [Operation metadata](operation-metadata.md); helpers `succeeded($status)`, `responseTypeFor($status, $contentType)`, `errorResponseFor(...)`, `errorTypeFor($status)`, `responseHeadersFor($status)`, `streamsResponse()`.

## Signatures with more than three parameters

Counted from the public and protected methods of the classes above.

| Parameters | Method |
| ---: | --- |
| 27 | `Operation::__construct` |
| 14 | `Security\OAuth2AuthorizationCode::__construct` |
| 9 | `Security\OAuth2ClientCredentials::__construct` |
| 6 | `ApiClient::__construct`, `ApiClient::connect`, `ApiClient::executeBody`, `Transport\RetryPolicy::__construct` |
| 5 | `Streaming\ServerSentEvent::__construct`, `ApiException::__construct`, `ApiClient::executeArguments`, `ApiClient::executeOperation`, `Transport\RedirectPolicy::__construct`, `Transport\Psr18Transport::__construct`, `Transport\Psr18Transport::sendResponse` |
| 4 | `Security\AuthorizationRequest::__construct`, `ApiException::fromResponse`, `TransportInterface::send`, `Psr18Transport::send` |
