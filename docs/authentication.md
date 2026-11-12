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

> **Current limit.** sdk-generator 1.0.0 only generates SDKs for APIs whose security schemes are `apiKey` (header, query, cookie), `http` `bearer` and `http` `basic`. A specification that declares an `oauth2` or `openIdConnect` scheme, or lists OAuth scopes in an operation, is **rejected** at generation time. The OAuth2 providers below are fully implemented in the runtime and work with any scheme the generator accepts as a bearer token; the generator does not yet emit `oauth2`/`openIdConnect` schemes.

## What each credential looks like

| Scheme type | Value in `SecurityCredentials` |
| --- | --- |
| API key (header, query or cookie) | the key as a string |
| HTTP bearer | the token as a string |
| HTTP basic | `new BasicAuthCredentials($username, $password)` |
| OAuth2, OpenID Connect or any token that expires | a token provider (below), or the token as a string |

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

## OAuth2: client credentials (machine to machine)

```php
use Dmorenof\SdkRuntime\Security\OAuth2ClientCredentials;

$oauth = new OAuth2ClientCredentials(
    'https://auth.example.com/oauth/token',   // token URL
    $clientId,
    $clientSecret,
    new \GuzzleHttp\Client(),                 // any PSR-18 client
    scopes: ['read'],
);

$config->securityCredentials = new SecurityCredentials(['OAuth' => $oauth]);
```

The provider:

1. requests a token on first use;
2. caches it and renews it 30 seconds before it expires (`leeway`);
3. after a `401`, discards the token, asks for a new one and **replays the request once**.

Pass `credentialsInBody: true` for token servers that do not accept HTTP Basic client authentication. By default tokens live in memory. To share them between processes, implement `TokenStoreInterface` (`get`, `set`, `delete`) and pass it as `store:`.

## OAuth2: authorization code with PKCE (a signed-in user)

For apps that act for a user. The runtime does the protocol; sending the browser to the login page is yours.

```php
use Dmorenof\SdkRuntime\Security\{AuthorizationRequest, OAuth2AuthorizationCode};

$flow = new OAuth2AuthorizationCode(
    authorizationUrl: 'https://auth.example.com/authorize',
    tokenUrl:         'https://auth.example.com/token',
    clientId:         $clientId,
    redirectUri:      'https://app.example.com/callback',
    http:             new \GuzzleHttp\Client(),
    clientSecret:     null,                    // null for public clients; PKCE protects them
    scopes:           ['read'],
    store:            $tokenStore,             // must outlive the request
    subject:          (string) $user->id,      // tokens belong to one user
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

| Situation | What happens |
| --- | --- |
| No token yet, or the refresh token was rejected | `ReauthorizationRequiredException`: send the user through step 1 again. |
| Token endpoint failed temporarily (5xx, network) | The stored token is kept; the error reaches you; the next call can retry. |
| User signs out | `$flow->forget()` |
| Is the user signed in? | `$flow->isAuthorized()` |

Security notes: the PKCE verifier is a secret (session only, never in the URL or logs); `state` is compared in constant time; the callback can verify the issuer (`issuer:`, RFC 9207); the token URL must be HTTPS except for localhost.

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

## Not supported yet

OAuth2 `password` and `implicit` flows, OpenID Connect discovery, scope checking, and mutual TLS (client certificates) are not implemented. For mutual TLS, configure the certificate on your PSR-18 client (`httpClient`).
