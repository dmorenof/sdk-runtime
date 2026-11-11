<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;

/**
 * One OAuth2 token endpoint: client authentication, the form POST and the response parsing shared by
 * every grant (client credentials, authorization code, refresh token).
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final class OAuth2TokenEndpoint
{
    /** Hosts that count as "this machine": TLS is not required toward them. */
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /** @var (\Closure(): int)|null */
    private readonly ?\Closure $clock;

    /**
     * @param ?string $clientSecret Null for a public client (id in the body, no secret).
     * @param bool $credentialsInBody Send client_id/client_secret in the form body instead of HTTP Basic.
     */
    public function __construct(
        public readonly string $tokenUrl,
        public readonly string $clientId,
        private readonly ?string $clientSecret,
        private readonly ClientInterface $http,
        private readonly bool $credentialsInBody = false,
        ?\Closure $clock = null,
    ) {
        self::assertUrl($tokenUrl, 'token URL');
        if ($clientId === '' || $clientSecret === '') {
            throw new \InvalidArgumentException('OAuth2 client id and secret must be nonempty.');
        }
        $this->clock = $clock;
    }

    /** OAuth endpoints need TLS: https, or http only toward the local machine (tests, loopback redirects). */
    public static function assertUrl(string $url, string $what): void
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if ($parts === false || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || !(($parts['scheme'] ?? '') === 'https' || (($parts['scheme'] ?? '') === 'http' && self::isLoopback($host)))) {
            throw new \InvalidArgumentException("OAuth2 $what must be an absolute https URL (http only for localhost) without credentials or fragment.");
        }
    }

    /** Whether a host (as parse_url reports it) is the local machine. */
    public static function isLoopback(string $host): bool
    {
        return in_array(strtolower($host), self::LOOPBACK_HOSTS, true);
    }

    /**
     * Scope tokens are separated by spaces on the wire, so one may not contain whitespace, quotes or backslashes.
     *
     * @param array<mixed> $scopes Checked at runtime: each scope must be a nonempty string without whitespace, quotes or backslashes.
     */
    public static function assertScopes(array $scopes): void
    {
        foreach ($scopes as $scope) {
            if (!is_string($scope) || $scope === '' || preg_match('/[\s"\\\\]/', $scope) === 1) {
                throw new \InvalidArgumentException('Invalid OAuth2 scope.');
            }
        }
    }

    public function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    /**
     * @param array<string, string> $form Grant parameters (grant_type, code, refresh_token, ...).
     * @param ?string $previousRefreshToken Kept when the response does not rotate it.
     */
    public function request(array $form, ?string $previousRefreshToken = null): OAuth2Token
    {
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];
        if ($this->clientSecret === null) {
            $form['client_id'] = $this->clientId;
        } elseif ($this->credentialsInBody) {
            $form['client_id'] = $this->clientId;
            $form['client_secret'] = $this->clientSecret;
        } else {
            // RFC 6749 section 2.3.1: form-urlencode id and secret before Basic encoding.
            $headers['Authorization'] = 'Basic ' . base64_encode(urlencode($this->clientId) . ':' . urlencode($this->clientSecret));
        }
        $response = $this->http->sendRequest(new Request('POST', $this->tokenUrl, $headers, Query::build($form)));
        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        if ($status !== 200 || !is_array($data)) {
            $error = is_array($data) && isset($data['error']) && is_string($data['error']) ? ': ' . substr($data['error'], 0, 64) : '';
            throw new OAuth2Exception("OAuth2 token request failed with HTTP $status$error.", $status);
        }
        $accessToken = $data['access_token'] ?? null;
        $type = $data['token_type'] ?? 'Bearer';
        if (!is_string($accessToken) || $accessToken === '' || !is_string($type) || strcasecmp($type, 'Bearer') !== 0) {
            throw new OAuth2Exception('OAuth2 token response has no usable Bearer access token.');
        }
        $expiresIn = $data['expires_in'] ?? null;
        $expiresAt = is_int($expiresIn) || (is_string($expiresIn) && ctype_digit($expiresIn)) ? $this->now() + (int) $expiresIn : null;
        $refresh = isset($data['refresh_token']) && is_string($data['refresh_token']) && $data['refresh_token'] !== ''
            ? $data['refresh_token'] : $previousRefreshToken;
        return new OAuth2Token($accessToken, $expiresAt, $refresh);
    }
}
