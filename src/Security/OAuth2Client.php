<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/**
 * Who the application is for the authorization server.
 *
 * A confidential client has a secret; a public client (native or single-page app) has none and relies on PKCE.
 * The redirect URI is only for the authorization code flow.
 */
final readonly class OAuth2Client
{
    /**
     * @param ?string $secret Null for a public client.
     * @param ?string $redirectUri Where the authorization server sends the user back: https, http on localhost, or a private-use scheme.
     * @param bool $secretInBody Send the credentials in the form body instead of HTTP Basic, for servers that do not accept Basic.
     */
    public function __construct(
        public string $id,
        public ?string $secret = null,
        public ?string $redirectUri = null,
        public bool $secretInBody = false,
    ) {
        if ($id === '' || $secret === '') {
            throw new \InvalidArgumentException('OAuth2 client id and secret must be nonempty.');
        }
        if ($redirectUri !== null) {
            self::assertRedirectUri($redirectUri);
        }
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
                $scheme === 'http' => OAuth2Endpoints::isLoopback($host),
                default => str_contains($scheme, '.'),
            };
        if (!$valid) {
            throw new \InvalidArgumentException('OAuth2 redirect URI must be https, http on localhost, or a private-use scheme containing a dot; without credentials or fragment.');
        }
    }

    /** @return array<string, string> Secrets are never exposed. */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'redirectUri' => $this->redirectUri];
    }
}
