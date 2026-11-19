<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/**
 * Where an OAuth2 authorization server lives. Only the token URL is always needed; the authorization URL is
 * for the authorization code flow, and the issuer lets that flow check the callback (RFC 9207).
 *
 * Endpoints need TLS: https, or http only toward the local machine.
 */
final readonly class OAuth2Endpoints
{
    /** Hosts that count as "this machine": TLS is not required toward them. */
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    public function __construct(
        public string $tokenUrl,
        public ?string $authorizationUrl = null,
        public ?string $issuer = null,
    ) {
        self::assertUrl($tokenUrl, 'token URL');
        if ($authorizationUrl !== null) {
            self::assertUrl($authorizationUrl, 'authorization URL');
        }
    }

    /**
     * Whether a host (as parse_url reports it) is the local machine.
     *
     * @internal Shared with OAuth2Client; not covered by the backward-compatibility promise.
     */
    public static function isLoopback(string $host): bool
    {
        return in_array(strtolower($host), self::LOOPBACK_HOSTS, true);
    }

    /** @internal Shared with OAuth2Implicit; not covered by the backward-compatibility promise. */
    public static function assertUrl(string $url, string $what): void
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if ($parts === false || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || !(($parts['scheme'] ?? '') === 'https' || (($parts['scheme'] ?? '') === 'http' && self::isLoopback($host)))) {
            throw new \InvalidArgumentException("OAuth2 $what must be an absolute https URL (http only for localhost) without credentials or fragment.");
        }
    }
}
