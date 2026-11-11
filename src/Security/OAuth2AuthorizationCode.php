<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

use GuzzleHttp\Psr7\Query;
use Psr\Http\Client\ClientInterface;

/**
 * OAuth2 authorization code grant with PKCE (RFC 6749 section 4.1, RFC 7636, S256), for web apps and public
 * clients. The runtime builds the authorization URL, validates the callback (state, error, optional issuer)
 * and exchanges the code; the redirect itself is the application's job. After `complete()` it is a token
 * provider that renews the access token with the refresh token.
 *
 * Tokens belong to one end user: create one instance per user, with a distinct `$subject` when instances
 * share a store.
 *
 *     $request = $flow->start();                  // keep $request in the session, redirect to $request->url
 *     $flow->complete($request, $_GET);           // on the redirect URI, once
 *     new SecurityCredentials(['OAuth' => $flow]) // then use it like any bearer token
 */
final class OAuth2AuthorizationCode extends CachedTokenProvider
{
    private const array RESERVED = ['response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method'];

    /**
     * @param ?string $clientSecret Null for a public client (native or single-page apps); PKCE protects it.
     * @param list<string> $scopes
     * @param string $subject Identifies the end user inside a shared token store.
     * @param ?string $issuer When set, the callback must carry a matching `iss` parameter (RFC 9207).
     * @param int $requestLifetime Seconds a started flow stays valid.
     * @param int $leeway Seconds before expiry at which a token is already considered stale.
     */
    public function __construct(
        private readonly string $authorizationUrl,
        string $tokenUrl,
        private readonly string $clientId,
        private readonly string $redirectUri,
        ClientInterface $http,
        ?string $clientSecret = null,
        private readonly array $scopes = [],
        ?TokenStoreInterface $store = null,
        string $subject = '',
        private readonly ?string $issuer = null,
        bool $credentialsInBody = false,
        private readonly int $requestLifetime = 600,
        int $leeway = 30,
        ?\Closure $clock = null,
    ) {
        OAuth2TokenEndpoint::assertUrl($authorizationUrl, 'authorization URL');
        self::assertRedirectUri($redirectUri);
        OAuth2TokenEndpoint::assertScopes($scopes);
        if ($requestLifetime < 1) {
            throw new \InvalidArgumentException('requestLifetime must be positive.');
        }
        parent::__construct(
            new OAuth2TokenEndpoint($tokenUrl, $clientId, $clientSecret, $http, $credentialsInBody, $clock),
            $store,
            self::storeKey($tokenUrl, $clientId, implode(' ', $scopes), $subject),
            $leeway,
        );
    }

    /**
     * RFC 6749 section 3.1.2 and RFC 8252: https, http only on the loopback interface, or a private-use
     * scheme named after a domain (`com.example.app:/callback`); never a fragment or embedded credentials.
     */
    private static function assertRedirectUri(string $uri): void
    {
        $parts = parse_url($uri);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $valid = $parts !== false && $scheme !== '' && !isset($parts['fragment']) && !isset($parts['user']) && !isset($parts['pass'])
            && match (true) {
                $scheme === 'https' => $host !== '',
                $scheme === 'http' => OAuth2TokenEndpoint::isLoopback($host),
                default => str_contains($scheme, '.'),
            };
        if (!$valid) {
            throw new \InvalidArgumentException('OAuth2 redirect URI must be https, http on localhost, or a private-use scheme containing a dot; without credentials or fragment.');
        }
    }

    /**
     * Starts a flow: a fresh state and PKCE verifier, and the URL to send the user to.
     *
     * @param array<string, string> $extra Additional authorization parameters (audience, prompt, login_hint...).
     */
    public function start(array $extra = []): AuthorizationRequest
    {
        foreach ($extra as $name => $value) {
            if (!is_string($name) || $name === '' || !is_string($value) || in_array($name, self::RESERVED, true)) {
                throw new \InvalidArgumentException('Extra authorization parameters must be string pairs that do not replace protocol parameters.');
            }
        }
        $verifier = Pkce::verifier();
        $state = Pkce::base64Url(random_bytes(32));
        $parameters = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
        ];
        if ($this->scopes !== []) {
            $parameters['scope'] = implode(' ', $this->scopes);
        }
        $parameters += ['state' => $state, 'code_challenge' => Pkce::challenge($verifier), 'code_challenge_method' => 'S256'] + $extra;

        $separator = str_contains($this->authorizationUrl, '?') ? '&' : '?';
        return new AuthorizationRequest(
            $this->authorizationUrl . $separator . Query::build($parameters),
            $state,
            $verifier,
            $this->endpoint->now() + $this->requestLifetime,
        );
    }

    /**
     * Finishes a flow with the parameters the authorization server sent to the redirect URI: checks the
     * request has not expired, the response is not an error, `state` (and `iss` when configured) match, and
     * exchanges the code together with the PKCE verifier. The token is stored and returned.
     *
     * @param array<string, mixed> $callback The redirect URI's query parameters (for example `$_GET`).
     * @throws OAuth2Exception
     */
    public function complete(AuthorizationRequest $request, array $callback): OAuth2Token
    {
        if ($this->endpoint->now() >= $request->expiresAt) {
            throw new OAuth2Exception('The authorization request has expired: start the flow again.');
        }
        $state = $callback['state'] ?? null;
        if (!is_string($state) || !hash_equals($request->state, $state)) {
            throw new OAuth2Exception('The callback state does not match the authorization request.');
        }
        if (isset($callback['error'])) {
            $error = is_string($callback['error']) && preg_match('/^[A-Za-z0-9_.\-]{1,64}$/D', $callback['error']) === 1 ? $callback['error'] : 'unknown_error';
            throw new OAuth2Exception("OAuth2 authorization failed: $error.");
        }
        if ($this->issuer !== null && (($callback['iss'] ?? null) !== $this->issuer)) {
            throw new OAuth2Exception('The callback issuer does not match the expected authorization server.');
        }
        $code = $callback['code'] ?? null;
        if (!is_string($code) || $code === '') {
            throw new OAuth2Exception('The callback carries no authorization code.');
        }
        if (!Pkce::isValidVerifier($request->codeVerifier)) {
            throw new \InvalidArgumentException('The authorization request holds an invalid PKCE verifier.');
        }

        $token = $this->endpoint->request([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $request->codeVerifier,
        ]);
        $this->save($token);
        return $token;
    }

    /** True when a token (possibly stale but renewable) is stored for this user. */
    public function isAuthorized(): bool
    {
        return $this->stored() !== null;
    }

    /** @throws ReauthorizationRequiredException No token, or one that can no longer be renewed: authorize again. */
    protected function obtain(?OAuth2Token $stale): OAuth2Token
    {
        if ($stale === null) {
            throw new ReauthorizationRequiredException('No OAuth2 token for this user: complete the authorization flow first.');
        }
        $this->discard();
        throw new ReauthorizationRequiredException('The OAuth2 token has expired and cannot be renewed: authorize again.');
    }

    /**
     * A refresh token the server rejects is gone for good, so the user must authorize again. Any other failure
     * (a server error, a malformed answer) may pass: the stored token is kept and the error reaches the caller.
     */
    protected function refreshFailed(OAuth2Exception $failure): void
    {
        if (!$failure->isRejection()) {
            throw $failure;
        }
        $this->discard();
        throw new ReauthorizationRequiredException('The OAuth2 refresh token was rejected: authorize again.', $failure->getCode(), $failure);
    }

    /** @return array<string, string> Secrets are never exposed. */
    public function __debugInfo(): array
    {
        return ['clientId' => $this->clientId, 'authorizationUrl' => $this->authorizationUrl];
    }
}
