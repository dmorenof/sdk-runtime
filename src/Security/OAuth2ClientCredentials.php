<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

use Psr\Http\Client\ClientInterface;

/**
 * OAuth2 client credentials grant (RFC 6749 section 4.4) with token caching and refresh-token renewal.
 * Uses any PSR-18 client; tokens live in an injectable store.
 */
final class OAuth2ClientCredentials implements TokenProviderInterface
{
    private readonly string $storeKey;
    private readonly TokenStoreInterface $store;
    private readonly OAuth2TokenEndpoint $endpoint;

    /**
     * @param list<string> $scopes
     * @param bool $credentialsInBody Send client_id/client_secret in the form body instead of HTTP Basic.
     * @param int $leeway Seconds before expiry at which a token is already considered stale.
     */
    public function __construct(
        private readonly string $tokenUrl,
        private readonly string $clientId,
        string $clientSecret,
        ClientInterface $http,
        private readonly array $scopes = [],
        ?TokenStoreInterface $store = null,
        bool $credentialsInBody = false,
        private readonly int $leeway = 30,
        ?\Closure $clock = null,
    ) {
        if ($clientId === '' || $clientSecret === '') {
            throw new \InvalidArgumentException('OAuth2 client id and secret must be nonempty.');
        }
        foreach ($scopes as $scope) {
            if (!is_string($scope) || $scope === '' || preg_match('/[\s"\\\\]/', $scope) === 1) {
                throw new \InvalidArgumentException('Invalid OAuth2 scope.');
            }
        }
        $this->endpoint = new OAuth2TokenEndpoint($tokenUrl, $clientId, $clientSecret, $http, $credentialsInBody, $clock);
        $this->store = $store ?? new InMemoryTokenStore();
        $this->storeKey = hash('sha256', implode("\0", [$tokenUrl, $clientId, implode(' ', $scopes)]));
    }

    public function accessToken(): string
    {
        $token = $this->store->get($this->storeKey);
        if ($token !== null && $token->isValid($this->endpoint->now(), $this->leeway)) {
            return $token->accessToken;
        }
        if ($token?->refreshToken !== null) {
            try {
                return $this->save($this->endpoint->request(['grant_type' => 'refresh_token', 'refresh_token' => $token->refreshToken], $token->refreshToken));
            } catch (\RuntimeException) {
                $this->store->delete($this->storeKey); // refresh token rejected: fall back to a new grant
            }
        }
        return $this->save($this->endpoint->request($this->scopes === [] ? ['grant_type' => 'client_credentials'] : ['grant_type' => 'client_credentials', 'scope' => implode(' ', $this->scopes)]));
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

    private function save(OAuth2Token $token): string
    {
        $this->store->set($this->storeKey, $token);
        return $token->accessToken;
    }
}
