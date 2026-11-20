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
| `executeArguments(...)`, `executeBody(...)`, `executeOperation(...)`, `operation($spec)` (protected) | Run an operation; `operation()` builds an `Operation` once per class and route. For generators |

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
| `ClientCertificate` | `ClientCertificate::fromFiles($certificate, $key, $passphrase)` or `ClientCertificate::configuredOnTheClient()`, the credential of a `mutualTLS` scheme |
| `TokenProviderInterface` | `accessToken(): string`, `invalidate(): void` |
| `OAuth2Endpoints`, `OAuth2Client`, `OAuth2Options` | What both providers are built from: where the server is, who the application is, and the optional settings |
| `OAuth2ClientCredentials` | Machine-to-machine tokens: `new OAuth2ClientCredentials($endpoints, $client, $options)`; cached, renewed, replayed after 401. Also `forget()` |
| `OAuth2AuthorizationCode` | User sign-in with PKCE: same three arguments; `start()`, `complete($request, $callback)`, `isAuthorized()`, `forget()` |
| `OAuth2Password` | The password grant, discouraged (RFC 9700): `new OAuth2Password($endpoints, $client, new ResourceOwner($user, $password), $options)`; cached, renewed with its refresh token, logs in again when it cannot |
| `ScopeAwareTokenProvider` | A token provider that knows its token's scopes: `grantedScopes(): ?array`. The OAuth2 providers are; a call that needs a scope the token lacks raises `InsufficientScopeException` (`scheme`, `missing`, `granted`) before it is sent. `ApiException` has `missingScopes()` and `isInsufficientScope()` |
| `OpenIdDiscovery` | OpenID Connect Discovery: `OpenIdDiscovery::forIssuer($issuer)` or `forUrl($openIdConnectUrl)`, then `configuration()` (an `OpenIdConfiguration`: `issuer`, `authorizationEndpoint`, `tokenEndpoint`, `endpoints()`, `supportsPkce()`); `forget()`. An SDK offers `Client::openIdDiscovery($scheme)` for its own scheme |
| `IdTokenValidator` | Validates an OpenID Connect ID token: `new IdTokenValidator($configuration, $clientId, new IdTokenOptions())`, then `validate($idToken, nonce: ..., accessToken: ...)` returns an `IdToken` (`subject()`, `issuer()`, `audience()`, `expiresAt()`, `claim($name)`) or raises `IdTokenException`. The token comes from `OAuth2Token::$idToken` |
| `OAuth2Implicit` | The implicit grant, discouraged (RFC 9700): `new OAuth2Implicit($authorizationUrl, $client, $options)`; `start()`, `complete($request, $redirectedUrl)`, `isAuthorized()`; no refresh token |
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
