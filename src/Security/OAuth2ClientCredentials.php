<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/**
 * OAuth2 client credentials grant (RFC 6749 section 4.4) with token caching and refresh-token renewal.
 * If renewing fails the provider simply asks for a new token, since the client can always authenticate again.
 *
 *     new OAuth2ClientCredentials(
 *         new OAuth2Endpoints('https://auth.example.com/token'),
 *         new OAuth2Client($clientId, $clientSecret),
 *         new OAuth2Options(scopes: ['read']),
 *     )
 */
final class OAuth2ClientCredentials extends CachedTokenProvider
{
    public function __construct(OAuth2Endpoints $endpoints, OAuth2Client $client, OAuth2Options $options = new OAuth2Options())
    {
        if ($client->secret === null) {
            throw new \InvalidArgumentException('The client credentials grant needs a client secret.');
        }
        parent::__construct($endpoints, $client, $options);
    }

    protected function obtain(?OAuth2Token $stale): OAuth2Token
    {
        $form = ['grant_type' => 'client_credentials'];
        if ($this->options->scopes !== []) {
            $form['scope'] = implode(' ', $this->options->scopes);
        }
        return $this->endpoint->request($form);
    }

    /** @return array<string, string> Secrets are never exposed. */
    public function __debugInfo(): array
    {
        return ['tokenUrl' => $this->endpoints->tokenUrl, 'clientId' => $this->client->id];
    }
}
