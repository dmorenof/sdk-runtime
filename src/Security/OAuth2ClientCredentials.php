<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;

/**
 * OAuth2 client credentials grant (RFC 6749 section 4.4) with token caching and refresh-token renewal.
 * Uses any PSR-18 client; tokens live in an injectable store.
 */
final class OAuth2ClientCredentials implements TokenProviderInterface
{
    private readonly string $storeKey;
    private readonly TokenStoreInterface $store;
    private readonly ?\Closure $clock;

    /**
     * @param list<string> $scopes
     * @param bool $credentialsInBody Send client_id/client_secret in the form body instead of HTTP Basic.
     * @param int $leeway Seconds before expiry at which a token is already considered stale.
     */
    public function __construct(
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly ClientInterface $http,
        private readonly array $scopes = [],
        ?TokenStoreInterface $store = null,
        private readonly bool $credentialsInBody = false,
        private readonly int $leeway = 30,
        ?\Closure $clock = null,
    ) {
        $parts = parse_url($tokenUrl);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('OAuth2 token URL must be an absolute HTTP(S) URL without credentials or fragment.');
        }
        if ($clientId === '' || $clientSecret === '') {
            throw new \InvalidArgumentException('OAuth2 client id and secret must be nonempty.');
        }
        foreach ($scopes as $scope) {
            if (!is_string($scope) || $scope === '' || preg_match('/[\s"\\\\]/', $scope) === 1) {
                throw new \InvalidArgumentException('Invalid OAuth2 scope.');
            }
        }
        $this->store = $store ?? new InMemoryTokenStore();
        $this->clock = $clock;
        $this->storeKey = hash('sha256', implode("\0", [$tokenUrl, $clientId, implode(' ', $scopes)]));
    }

    public function accessToken(): string
    {
        $token = $this->store->get($this->storeKey);
        if ($token !== null && $token->isValid($this->now(), $this->leeway)) {
            return $token->accessToken;
        }
        if ($token?->refreshToken !== null) {
            try {
                return $this->save($this->request(['grant_type' => 'refresh_token', 'refresh_token' => $token->refreshToken], $token->refreshToken));
            } catch (\RuntimeException) {
                $this->store->delete($this->storeKey); // refresh token rejected: fall back to a new grant
            }
        }
        return $this->save($this->request(['grant_type' => 'client_credentials']));
    }

    public function invalidate(): void
    {
        $token = $this->store->get($this->storeKey);
        if ($token?->refreshToken !== null) {
            // Keep the refresh token but force renewal on the next call.
            $this->store->set($this->storeKey, new OAuth2Token($token->accessToken, 0, $token->refreshToken));
            return;
        }
        $this->store->delete($this->storeKey);
    }

    /** @return array<string, string> Secrets are never exposed. */
    public function __debugInfo(): array
    {
        return ['tokenUrl' => $this->tokenUrl, 'clientId' => $this->clientId];
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    private function save(OAuth2Token $token): string
    {
        $this->store->set($this->storeKey, $token);
        return $token->accessToken;
    }

    /** @param array<string, string> $form */
    private function request(array $form, ?string $previousRefreshToken = null): OAuth2Token
    {
        if ($this->scopes !== [] && $form['grant_type'] === 'client_credentials') {
            $form['scope'] = implode(' ', $this->scopes);
        }
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];
        if ($this->credentialsInBody) {
            $form['client_id'] = $this->clientId;
            $form['client_secret'] = $this->clientSecret;
        } else {
            // RFC 6749 section 2.3.1: form-urlencode id and secret before Basic encoding.
            $headers['Authorization'] = 'Basic ' . base64_encode(
                urlencode($this->clientId) . ':' . urlencode($this->clientSecret),
            );
        }
        $response = $this->http->sendRequest(new Request('POST', $this->tokenUrl, $headers, Query::build($form)));
        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        if ($status !== 200 || !is_array($data)) {
            $error = is_array($data) && isset($data['error']) && is_string($data['error']) ? ': ' . substr($data['error'], 0, 64) : '';
            throw new \RuntimeException("OAuth2 token request failed with HTTP $status$error.");
        }
        $accessToken = $data['access_token'] ?? null;
        $type = $data['token_type'] ?? 'Bearer';
        if (!is_string($accessToken) || $accessToken === '' || !is_string($type) || strcasecmp($type, 'Bearer') !== 0) {
            throw new \RuntimeException('OAuth2 token response has no usable Bearer access token.');
        }
        $expiresIn = $data['expires_in'] ?? null;
        $expiresAt = is_int($expiresIn) || (is_string($expiresIn) && ctype_digit($expiresIn))
            ? $this->now() + (int) $expiresIn : null;
        $refresh = isset($data['refresh_token']) && is_string($data['refresh_token']) && $data['refresh_token'] !== ''
            ? $data['refresh_token'] : $previousRefreshToken;
        return new OAuth2Token($accessToken, $expiresAt, $refresh);
    }
}
