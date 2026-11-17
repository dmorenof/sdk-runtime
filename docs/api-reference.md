# API reference

Every public class, grouped by purpose. Signatures are abbreviated; the authoritative list is [`tests/Fixtures/public-api.txt`](../tests/Fixtures/public-api.txt), which a test keeps in sync with the code. What may change between versions: [Public API and versioning](PUBLIC_API.md).

Namespace: `Dmorenof\SdkRuntime` unless shown.

## Client

### `ApiClient` (abstract; generated SDKs extend it)

| Member | Purpose |
| --- | --- |
| `static connect(Configuration\|string $configuration)` | Create a client. A string is a base URL with every other option at its default |
| `resource(string $type): static` | A client for one resource class, sharing the transport, credentials and server choice |
| `lastResponse(): ?ResponseInfo` | Status and headers of the last response of *this* instance |
| `static serverUrl($server = 0, $variables = [])` | Base URL for a declared root server |
| `const SECURITY_SCHEMES`, `const SERVERS` | Declared by the SDK. See [Operation metadata](operation-metadata.md) |
| `executeArguments(...)`, `executeBody(...)`, `executeOperation(...)` (protected) | Run an operation. For generators |

A client never changes after it is created. Every option is set in `Configuration`.

### `Configuration`

Plain properties, set by name before connecting: `baseUrl`, `httpClient`, `headers`, `securityCredentials`, `transport`, `middleware`, `retryPolicy`, `redirectPolicy`, `serverOverrides`, `server`, `serverVariables`.

### `RequestInput`

The values of one call split by where they travel: `new RequestInput(path: [], query: [], headers: [], body: null, bodyPresent: null)`. For `executeOperation()`.

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
| `OAuth2Endpoints`, `OAuth2Client`, `OAuth2Options` | What both providers are built from: where the server is, who the application is, and the optional settings |
| `OAuth2ClientCredentials` | Machine-to-machine tokens: `new OAuth2ClientCredentials($endpoints, $client, $options)`; cached, renewed, replayed after 401. Also `forget()` |
| `OAuth2AuthorizationCode` | User sign-in with PKCE: same three arguments; `start()`, `complete($request, $callback)`, `isAuthorized()`, `forget()` |
| `AuthorizationRequest` | State of a started flow: `$url`, `$state`, `$codeVerifier`, `$expiresAt`; `toArray()`, `fromArray()` |
| `OAuth2Token` | `$accessToken`, `$expiresAt`, `$refreshToken`, `isValid($now, $leeway)` |
| `TokenStoreInterface`, `InMemoryTokenStore` | `get`, `set`, `delete` by key |
| `OAuth2Exception`, `ReauthorizationRequiredException` | Token endpoint failure; the user must sign in again. `isRejection()` |

## Transport — `Transport\*`

| Class | Purpose |
| --- | --- |
| `TransportInterface` | `send($method, $url, $headers, $body): HttpResponse` |
| `Psr18Transport`, `Psr18Options` | The default transport over any PSR-18 client, and its options: default headers, middleware, retries, redirects. Adds streaming |
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

The rule is at most three arguments, four where it reads better; options travel in configuration objects ([Design decisions](DECISIONS.md), R1 and R2). These are the signatures over three, counted from the public and protected API:

| Parameters | Signature | Why |
| ---: | --- | --- |
| 6 | `Security\OAuth2Options::__construct`, `Transport\RetryPolicy::__construct` | Configuration objects: every argument is an optional setting, used by name |
| 5 | `RequestInput::__construct`, `Transport\RedirectPolicy::__construct`, `Streaming\ServerSentEvent::__construct` | Configuration or value objects, used by name |
| 4 | `Security\OAuth2Client::__construct`, `Security\AuthorizationRequest::__construct` | Small value objects |
| 4 | `ApiException::__construct`, `ApiClient::executeArguments`, `ApiClient::executeBody` | Response + operation + payload + cause; path + parameters + body |
| 4 | `Transport\TransportInterface::send`, `Transport\Psr18Transport::send` | method, URL, headers, body |
