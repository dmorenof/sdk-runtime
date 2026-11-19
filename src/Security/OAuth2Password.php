<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/**
 * OAuth2 resource owner password credentials grant (RFC 6749 section 4.3), with token caching and refresh-token
 * renewal. The application holds the end user's password, which is why OAuth 2.0 Security Best Current Practice
 * (RFC 9700) says not to use this grant and OAuth 2.1 drops it: use it only because an API leaves you no other way.
 * If renewing fails (or the token cannot be renewed) the provider logs in again with the same credentials, so a
 * password that stopped working surfaces as an OAuth2Exception.
 *
 * Tokens belong to one end user: the user name tells them apart in a shared store unless you give a `$subject`.
 *
 *     new OAuth2Password(
 *         new OAuth2Endpoints('https://auth.example.com/token'),
 *         new OAuth2Client($clientId, $clientSecret),
 *         new ResourceOwner($username, $password),
 *         new OAuth2Options(scopes: ['read']),
 *     )
 */
final class OAuth2Password extends CachedTokenProvider
{
    private readonly ResourceOwner $owner;

    public function __construct(OAuth2Endpoints $endpoints, OAuth2Client $client, ResourceOwner $owner, OAuth2Options $options = new OAuth2Options())
    {
        $this->owner = $owner;
        parent::__construct($endpoints, $client, $options->subject === '' ? $options->withSubject($owner->username) : $options);
    }

    protected function obtain(?OAuth2Token $stale): OAuth2Token
    {
        $form = ['grant_type' => 'password', 'username' => $this->owner->username, 'password' => $this->owner->password];
        if ($this->options->scopes !== []) {
            $form['scope'] = implode(' ', $this->options->scopes);
        }
        return $this->endpoint->request($form);
    }

    /** @return array<string, string> Secrets are never exposed. */
    public function __debugInfo(): array
    {
        return ['tokenUrl' => $this->endpoints->tokenUrl, 'clientId' => $this->client->id, 'username' => $this->owner->username];
    }
}
