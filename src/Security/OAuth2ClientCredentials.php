<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

use Psr\Http\Client\ClientInterface;

/**
 * OAuth2 client credentials grant (RFC 6749 section 4.4) with token caching and refresh-token renewal.
 * Uses any PSR-18 client; tokens live in an injectable store. If renewing fails the provider simply asks for a
 * new token, since the client can always authenticate again.
 */
final class OAuth2ClientCredentials extends CachedTokenProvider
{
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
        int $leeway = 30,
        ?\Closure $clock = null,
    ) {
        if ($clientId === '' || $clientSecret === '') {
            throw new \InvalidArgumentException('OAuth2 client id and secret must be nonempty.');
        }
        OAuth2TokenEndpoint::assertScopes($scopes);
        parent::__construct(
            new OAuth2TokenEndpoint($tokenUrl, $clientId, $clientSecret, $http, $credentialsInBody, $clock),
            $store,
            self::storeKey($tokenUrl, $clientId, implode(' ', $scopes)),
            $leeway,
        );
    }

    protected function obtain(?OAuth2Token $stale): OAuth2Token
    {
        $form = ['grant_type' => 'client_credentials'];
        if ($this->scopes !== []) {
            $form['scope'] = implode(' ', $this->scopes);
        }
        return $this->endpoint->request($form);
    }

    /** @return array<string, string> Secrets are never exposed. */
    public function __debugInfo(): array
    {
        return ['tokenUrl' => $this->tokenUrl, 'clientId' => $this->clientId];
    }
}
