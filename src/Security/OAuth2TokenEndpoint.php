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
    private readonly ClientInterface $http;

    public function __construct(
        private readonly OAuth2Endpoints $endpoints,
        private readonly OAuth2Client $client,
        private readonly OAuth2Options $options,
    ) {
        $this->http = $options->http ?? new \GuzzleHttp\Client(['http_errors' => false]);
    }

    public function now(): int
    {
        return $this->options->clock !== null ? ($this->options->clock)() : time();
    }

    /**
     * @param array<string, string> $form Grant parameters (grant_type, code, refresh_token, ...).
     * @param ?string $previousRefreshToken Kept when the response does not rotate it.
     * @param list<string>|null $previousScopes The scopes of the token being renewed: a response that names none grants them again.
     */
    public function request(array $form, ?string $previousRefreshToken = null, ?array $previousScopes = null): OAuth2Token
    {
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];
        if ($this->client->secret === null) {
            $form['client_id'] = $this->client->id;
        } elseif ($this->client->secretInBody) {
            $form['client_id'] = $this->client->id;
            $form['client_secret'] = $this->client->secret;
        } else {
            // RFC 6749 section 2.3.1: form-urlencode id and secret before Basic encoding.
            $headers['Authorization'] = 'Basic ' . base64_encode(urlencode($this->client->id) . ':' . urlencode($this->client->secret));
        }
        $response = $this->http->sendRequest(new Request('POST', $this->endpoints->tokenUrl, $headers, Query::build($form)));
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
        $requested = isset($form['scope']) ? self::scopes($form['scope']) : ($previousScopes ?? ($this->options->scopes !== [] ? $this->options->scopes : null));
        // RFC 6749 section 5.1: a response without `scope` grants what was requested.
        $scopes = isset($data['scope']) && is_string($data['scope']) ? (self::scopes($data['scope']) ?? $requested) : $requested;
        $idToken = isset($data['id_token']) && is_string($data['id_token']) && $data['id_token'] !== '' ? $data['id_token'] : null;
        return new OAuth2Token($accessToken, $expiresAt, $refresh, $scopes, $idToken);
    }

    /**
     * The scope tokens of a space-separated `scope` value (RFC 6749 section 3.3), or null when it holds anything else.
     *
     * @internal Shared with OAuth2Implicit; not covered by the backward-compatibility promise.
     * @return list<string>|null
     */
    public static function scopes(string $value): ?array
    {
        $tokens = preg_split('/ +/', trim($value, ' '), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            return null;
        }
        foreach ($tokens as $token) {
            if (preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+$/D', $token) !== 1) {
                return null;
            }
        }
        return $tokens;
    }
}
