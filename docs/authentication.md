# Authentication

An API declares **security schemes** (for example `ApiKeyAuth`, `BearerAuth`, `OAuth`) and says which operations need which. The SDK already knows both. You only provide the secrets, keyed by scheme name:

```php
use Dmorenof\SdkRuntime\Security\SecurityCredentials;

$config = new Configuration();
$config->securityCredentials = new SecurityCredentials([
    'ApiKeyAuth' => 'k_live_123',          // API key, in the header/query/cookie the API declares
]);
Users::configure($config);
```

The scheme names are the ones in the API's specification (`components.securitySchemes`). A name the API does not use is ignored. If an operation requires credentials and none of its alternatives can be satisfied, nothing is sent and the call throws `InvalidArgumentException: No configured credentials satisfy this operation security requirement.` Operations the API leaves open (or marks as optionally authenticated) never need credentials.

> **Generator support.** sdk-generator 1.9.0 and later also generate SDKs for APIs that declare a `mutualTLS` scheme (OpenAPI 3.1). sdk-generator 1.3.0 and later generate SDKs for APIs that declare `oauth2` or `openIdConnect` schemes, besides `apiKey` (header, query, cookie) and `http` `basic` and `bearer`. Give the scheme's name a token or a token provider (below). The generator does not copy the scheme's endpoints or scopes into the SDK, so you build the provider with the URLs and scopes you need. Earlier generator versions reject those schemes.

## What each credential looks like

| Scheme type | Value in `SecurityCredentials` |
| --- | --- |
| API key (header, query or cookie) | the key as a string |
| HTTP bearer | the token as a string |
| HTTP basic | `new BasicAuthCredentials($username, $password)` |
| OAuth2, OpenID Connect or any token that expires | a token provider (below), or the token as a string |
| Mutual TLS (`mutualTLS`) | `ClientCertificate::fromFiles($certificate, $key, $passphrase)`, or `ClientCertificate::configuredOnTheClient()` (below) |

Strings may not be empty or contain line breaks. Credentials never appear in `var_dump`, logs or exception messages: debug output only lists *which* schemes are configured.

```php
use Dmorenof\SdkRuntime\Security\BasicAuthCredentials;

new SecurityCredentials([
    'ApiKeyAuth' => $key,
    'BasicAuth'  => new BasicAuthCredentials('ada', $password),
]);
```

### Alternatives and combinations

When an operation lists several requirements, the runtime uses the first one you can satisfy. When one requirement names two schemes (both required), it applies both. You do not configure this; just provide every credential you have.

## OAuth2

Both OAuth2 flows are built from the same three small objects:

| Object | Holds |
| --- | --- |
| `OAuth2Endpoints` | `tokenUrl`, `authorizationUrl` (authorization code only), `issuer` (checks the callback, RFC 9207) |
| `OAuth2Client` | `id`, `secret` (null for a public client), `redirectUri` (authorization code only), `secretInBody` |
| `OAuth2Options` | `scopes`, `http` (PSR-18 client for token requests), `store`, `subject`, `leeway`, `clock`: all optional |

Token endpoints need TLS: `https`, or `http` only toward `localhost`.

### Client credentials (machine to machine)

```php
use Dmorenof\SdkRuntime\Security\{OAuth2Client, OAuth2ClientCredentials, OAuth2Endpoints, OAuth2Options};

$oauth = new OAuth2ClientCredentials(
    new OAuth2Endpoints('https://auth.example.com/oauth/token'),
    new OAuth2Client($clientId, $clientSecret),
    new OAuth2Options(scopes: ['read']),
);

$config->securityCredentials = new SecurityCredentials(['OAuth' => $oauth]);
```

The provider:

1. requests a token on first use;
2. caches it and renews it 30 seconds before it expires (`leeway`);
3. after a `401`, discards the token, asks for a new one and **replays the request once**.

Use `new OAuth2Client($id, $secret, secretInBody: true)` for token servers that do not accept HTTP Basic client authentication. Tokens live in memory unless you give `OAuth2Options` a `store:`; implement `TokenStoreInterface` (`get`, `set`, `delete`) to share tokens between processes.

### Authorization code with PKCE (a signed-in user)

For apps that act for a user. The runtime does the protocol; sending the browser to the login page is yours.

```php
use Dmorenof\SdkRuntime\Security\{AuthorizationRequest, OAuth2AuthorizationCode, OAuth2Client, OAuth2Endpoints, OAuth2Options};

$flow = new OAuth2AuthorizationCode(
    new OAuth2Endpoints(
        tokenUrl:         'https://auth.example.com/token',
        authorizationUrl: 'https://auth.example.com/authorize',
        issuer:           'https://auth.example.com',     // optional: verified on the callback
    ),
    new OAuth2Client(
        id:          $clientId,
        secret:      null,                                // null for public clients; PKCE protects them
        redirectUri: 'https://app.example.com/callback',
    ),
    new OAuth2Options(
        scopes:  ['read'],
        store:   $tokenStore,                             // must outlive the request
        subject: (string) $user->id,                      // tokens belong to one user
    ),
);

// 1. Start: keep the request server-side, redirect the browser to its URL.
$request = $flow->start();
$_SESSION['oauth'] = $request->toArray();      // state, PKCE verifier, expiry
header('Location: ' . $request->url);

// 2. Callback: checks state, errors and issuer, then exchanges the code.
$flow->complete(AuthorizationRequest::fromArray($_SESSION['oauth']), $_GET);

// 3. Use it like any token. It renews itself and replays once after a 401.
$config->securityCredentials = new SecurityCredentials(['OAuth' => $flow]);
```

A started flow stays valid for 10 minutes.

| Situation | What happens |
| --- | --- |
| No token yet, or the refresh token was rejected | `ReauthorizationRequiredException`: send the user through step 1 again. |
| Token endpoint failed temporarily (5xx, network) | The stored token is kept; the error reaches you; the next call can retry. |
| User signs out | `$flow->forget()` |
| Is the user signed in? | `$flow->isAuthorized()` |

Security notes: the PKCE verifier is a secret (session only, never in the URL or logs); `state` is compared in constant time; the redirect URI must be `https`, `http` on localhost, or a private-use scheme such as `com.example.app:/callback`.

### Password grant (the user's own credentials)

The password grant (RFC 6749 section 4.3) sends the end user's name and password to the token endpoint from your application. OAuth 2.0 Security Best Current Practice (RFC 9700) says not to use it and OAuth 2.1 drops it, because your application then holds a password the authorization server should be the only one to see. Use it only when an API leaves you no other way.

```php
use Dmorenof\SdkRuntime\Security\{OAuth2Client, OAuth2Endpoints, OAuth2Options, OAuth2Password, ResourceOwner};

$oauth = new OAuth2Password(
    new OAuth2Endpoints('https://auth.example.com/oauth/token'),
    new OAuth2Client($clientId, $clientSecret),          // a public client passes no secret
    new ResourceOwner($username, $password),
    new OAuth2Options(scopes: ['read']),
);

$config->securityCredentials = new SecurityCredentials(['OAuth' => $oauth]);
```

It works like the client credentials provider: the token is kept (in memory, or in the store you give), renewed with its refresh token before it expires, and replayed once after a `401`. When the token cannot be renewed, it logs in again with the same name and password; a password the server refuses raises `OAuth2Exception` (with the error code, never the password). The user name tells users apart in a shared store, unless you pass your own `subject`.

### Implicit grant (a token in the redirect)

The implicit grant (RFC 6749 section 4.2) returns the token in the **fragment** of the redirect URI, which a browser never sends to a server. It is for an application that sees the URL the user was sent back to: a command-line or desktop tool, or a page script that hands the URL over. RFC 9700 says not to use it either: prefer the authorization code flow with PKCE when the API offers it. There is no refresh token, so when the token expires the user authorizes again.

```php
use Dmorenof\SdkRuntime\Security\{OAuth2Client, OAuth2Implicit};

$flow = new OAuth2Implicit('https://auth.example.com/authorize', new OAuth2Client($clientId, redirectUri: 'http://localhost:8123/callback'));
$request = $flow->start();                   // send the user to $request->url; keep $request
$flow->complete($request, $redirectedUrl);   // the full URL the user was sent back to, fragment included

$config->securityCredentials = new SecurityCredentials(['OAuth' => $flow]);
```

`complete()` checks that the request has not expired, that the URL is the registered redirect URI, that the response is not an error, and that `state` matches, and refuses a token that is not a Bearer token. When `accessToken()` finds no valid token it raises `ReauthorizationRequiredException`.

## Your own token source

Anything that returns a bearer string can be a provider:

```php
use Dmorenof\SdkRuntime\Security\TokenProviderInterface;

final class VaultToken implements TokenProviderInterface
{
    public function accessToken(): string { return $this->vault->read('api-token'); }
    public function invalidate(): void    { $this->vault->refresh(); }   // called after a 401
}

new SecurityCredentials(['BearerAuth' => new VaultToken($vault)]);
```

`invalidate()` is called after a `401`; the request is then replayed once with a fresh `accessToken()` (only if its body can be sent again).

## Mutual TLS (client certificates)

A `mutualTLS` scheme (OpenAPI 3.1) sends no header: the client proves who it is in the TLS handshake with a certificate. The credential is a `ClientCertificate`:

```php
use Dmorenof\SdkRuntime\Security\ClientCertificate;

$config->securityCredentials = new SecurityCredentials([
    'Mtls' => ClientCertificate::fromFiles('/etc/ssl/client.pem', '/etc/ssl/client.key', $passphrase),
]);
```

`fromFiles()` takes the PEM certificate (which may also hold the key), optionally a separate PEM private key, and the key's passphrase if it has one. The default Guzzle transport hands them to the handshake of each call that requires the scheme, and of no other call. If the call is redirected to another host, the certificate is not presented there. The files are read by cURL when the call is made, so a missing or unreadable file shows up as a cURL error.

A PSR-18 client other than Guzzle cannot take a certificate per call, and neither can a custom transport. Set the certificate up on that client, and tell the SDK so:

```php
$config->securityCredentials = new SecurityCredentials(['Mtls' => ClientCertificate::configuredOnTheClient()]);
```

That satisfies the requirement and adds nothing to the call. Passing `fromFiles()` to such a client is an error (`LogicException`), not a call without a certificate.

## Not supported yet

OpenID Connect discovery and scope checking are not implemented.
