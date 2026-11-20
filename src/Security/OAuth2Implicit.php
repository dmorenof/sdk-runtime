<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

use GuzzleHttp\Psr7\Query;

/**
 * OAuth2 implicit grant (RFC 6749 section 4.2). The authorization server sends the access token in the fragment of
 * the redirect URI, which a browser never passes on to a server: this is for an application that sees the URL the
 * user was sent back to (a command-line or desktop tool, or a page script that hands the URL over). OAuth 2.0
 * Security Best Current Practice (RFC 9700) says not to use the implicit grant and OAuth 2.1 drops it: use the
 * authorization code flow with PKCE when the API offers it. There is no refresh token: when the token expires,
 * the user authorizes again.
 *
 * Tokens belong to one end user: create one instance per user, with a distinct `$subject` when instances share a store.
 *
 *     $flow = new OAuth2Implicit('https://auth.example.com/authorize', new OAuth2Client($id, redirectUri: $uri));
 *     $request = $flow->start();                 // send the user to $request->url, keep $request
 *     $flow->complete($request, $redirectedUrl); // the full URL the user was sent back to, fragment included
 *     new SecurityCredentials(['OAuth' => $flow])
 */
final class OAuth2Implicit implements TokenProviderInterface
{
    private const array RESERVED = ['response_type', 'client_id', 'redirect_uri', 'scope', 'state'];

    /** Seconds a started flow stays valid. */
    private const int REQUEST_LIFETIME = 600;

    private readonly string $redirectUri;
    private readonly TokenStoreInterface $store;
    private readonly string $storeKey;

    public function __construct(
        private readonly string $authorizationUrl,
        private readonly OAuth2Client $client,
        private readonly OAuth2Options $options = new OAuth2Options(),
    ) {
        OAuth2Endpoints::assertUrl($authorizationUrl, 'authorization URL');
        if ($client->redirectUri === null) {
            throw new \InvalidArgumentException('The implicit flow needs a redirect URI.');
        }
        $this->redirectUri = $client->redirectUri;
        $this->store = $options->store ?? new InMemoryTokenStore();
        $this->storeKey = hash('sha256', implode("\0", ['implicit', $authorizationUrl, $client->id, implode(' ', $options->scopes), $options->subject]));
    }

    /**
     * Starts a flow: a fresh state, and the URL to send the user to.
     *
     * @param array<mixed> $extra Checked at runtime to be string pairs. Additional authorization parameters (audience, prompt, login_hint...).
     */
    public function start(array $extra = []): AuthorizationRequest
    {
        foreach ($extra as $name => $value) {
            if (!is_string($name) || $name === '' || !is_string($value) || in_array($name, self::RESERVED, true)) {
                throw new \InvalidArgumentException('Extra authorization parameters must be string pairs that do not replace protocol parameters.');
            }
        }
        $state = Pkce::base64Url(random_bytes(32));
        $parameters = ['response_type' => 'token', 'client_id' => $this->client->id, 'redirect_uri' => $this->redirectUri];
        if ($this->options->scopes !== []) {
            $parameters['scope'] = implode(' ', $this->options->scopes);
        }
        $parameters += ['state' => $state] + $extra;
        $separator = str_contains($this->authorizationUrl, '?') ? '&' : '?';
        // An implicit request has no PKCE verifier: the field stays empty.
        return new AuthorizationRequest($this->authorizationUrl . $separator . Query::build($parameters), $state, '', $this->now() + self::REQUEST_LIFETIME);
    }

    /**
     * Finishes a flow with the URL the user was sent back to: checks the request has not expired, the URL is the
     * redirect URI, the response is not an error and `state` matches, then stores the token it carries and returns it.
     *
     * @throws OAuth2Exception
     */
    public function complete(AuthorizationRequest $request, string $redirectedUrl): OAuth2Token
    {
        if ($this->now() >= $request->expiresAt) {
            throw new OAuth2Exception('The authorization request has expired: start the flow again.');
        }
        $parts = parse_url($redirectedUrl);
        $registered = parse_url($this->redirectUri);
        if ($parts === false || $registered === false
            || strtolower($parts['scheme'] ?? '') !== strtolower($registered['scheme'] ?? '')
            || strtolower($parts['host'] ?? '') !== strtolower($registered['host'] ?? '')
            || ($parts['port'] ?? null) !== ($registered['port'] ?? null)
            || ($parts['path'] ?? '') !== ($registered['path'] ?? '')) {
            throw new OAuth2Exception('The redirected URL is not the registered redirect URI.');
        }
        parse_str((string) ($parts['fragment'] ?? ''), $response);
        $state = $response['state'] ?? null;
        if (!is_string($state) || !hash_equals($request->state, $state)) {
            throw new OAuth2Exception('The response state does not match the authorization request.');
        }
        if (isset($response['error'])) {
            $error = is_string($response['error']) && preg_match('/^[A-Za-z0-9_.\-]{1,64}$/D', $response['error']) === 1 ? $response['error'] : 'unknown_error';
            throw new OAuth2Exception("OAuth2 authorization failed: $error.");
        }
        $accessToken = $response['access_token'] ?? null;
        $type = $response['token_type'] ?? 'Bearer';
        if (!is_string($accessToken) || $accessToken === '' || preg_match('/[\r\n]/', $accessToken) === 1 || !is_string($type) || strcasecmp($type, 'Bearer') !== 0) {
            throw new OAuth2Exception('The response has no usable Bearer access token.');
        }
        $expiresIn = $response['expires_in'] ?? null;
        $expiresAt = is_string($expiresIn) && ctype_digit($expiresIn) ? $this->now() + (int) $expiresIn : null;
        $token = new OAuth2Token($accessToken, $expiresAt);
        $this->store->set($this->storeKey, $token);
        return $token;
    }

    /** True when a token that has not expired is stored for this user. */
    public function isAuthorized(): bool
    {
        return $this->stored() !== null;
    }

    /** @throws ReauthorizationRequiredException No token, or an expired one (an implicit grant has no refresh token): authorize again. */
    public function accessToken(): string
    {
        $token = $this->stored();
        if ($token === null) {
            throw new ReauthorizationRequiredException('No valid OAuth2 token for this user: complete the authorization flow first.');
        }
        return $token->accessToken;
    }

    public function invalidate(): void
    {
        $this->store->delete($this->storeKey);
    }

    private function stored(): ?OAuth2Token
    {
        $token = $this->store->get($this->storeKey);
        return $token !== null && $token->isValid($this->now(), $this->options->leeway) ? $token : null;
    }

    private function now(): int
    {
        return $this->options->clock !== null ? ($this->options->clock)() : time();
    }

    /** @return array<string, string> Secrets are never exposed. */
    public function __debugInfo(): array
    {
        return ['clientId' => $this->client->id, 'authorizationUrl' => $this->authorizationUrl];
    }
}
