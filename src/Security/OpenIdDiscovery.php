<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;

/**
 * OpenID Connect Discovery 1.0: fetches the provider's configuration document and hands its endpoints to the OAuth2
 * flows, so you name the issuer (or the `openIdConnectUrl` of the API's contract) instead of copying three URLs.
 * The document is kept for `$ttl` seconds.
 *
 *     $endpoints = OpenIdDiscovery::forIssuer('https://accounts.example.com')->configuration()->endpoints();
 *     $flow = new OAuth2AuthorizationCode($endpoints, $client, $options);
 *
 * The document is only believed when it is what it claims: it must come over TLS, be a JSON object, and name an issuer
 * that is the issuer you asked for (or, when you gave a URL, one on the same host), so a document cannot send you to
 * another provider's endpoints.
 */
final class OpenIdDiscovery
{
    private const string WELL_KNOWN = '/.well-known/openid-configuration';

    /** Largest document read, in bytes. */
    private const int MAX_BYTES = 1048576;

    private readonly ClientInterface $http;
    private ?OpenIdConfiguration $configuration = null;
    private int $fetchedAt = 0;

    /**
     * @param string $configurationUrl The URL of the discovery document.
     * @param ?string $issuer The issuer the document must name; null accepts any issuer on the host of the URL.
     */
    public function __construct(
        private readonly string $configurationUrl,
        private readonly ?string $issuer = null,
        private readonly DiscoveryOptions $options = new DiscoveryOptions(),
    ) {
        OAuth2Endpoints::assertUrl($configurationUrl, 'discovery URL');
        if ($issuer !== null) {
            OAuth2Endpoints::assertUrl($issuer, 'issuer');
        }
        // A document fetched when an SDK starts up must not hang it: bounded waits for the default client.
        $this->http = $options->http ?? new \GuzzleHttp\Client(['http_errors' => false, 'timeout' => 15, 'connect_timeout' => 5]);
    }

    /**
     * The provider with this issuer: its document is at the issuer's URL, without a final slash, followed by
     * `/.well-known/openid-configuration`; the issuer it names must be this one exactly (a provider whose issuer ends
     * in a slash, like Auth0, is given with it).
     */
    public static function forIssuer(string $issuer, DiscoveryOptions $options = new DiscoveryOptions()): self
    {
        return new self(rtrim($issuer, '/') . self::WELL_KNOWN, $issuer, $options);
    }

    /** The provider whose document is at this URL, as the `openIdConnectUrl` of an OpenAPI security scheme. */
    public static function forUrl(string $configurationUrl, DiscoveryOptions $options = new DiscoveryOptions()): self
    {
        return new self($configurationUrl, null, $options);
    }

    /** @throws OAuth2Exception The document could not be fetched, or is not one this SDK can trust. */
    public function configuration(): OpenIdConfiguration
    {
        $now = $this->now();
        if ($this->configuration === null || $now - $this->fetchedAt >= $this->options->ttl) {
            $this->configuration = $this->fetch();
            $this->fetchedAt = $now;
        }
        return $this->configuration;
    }

    /** Forgets the document, so the next use fetches it again. */
    public function forget(): void
    {
        $this->configuration = null;
    }

    private function fetch(): OpenIdConfiguration
    {
        $response = $this->http->sendRequest(new Request('GET', $this->configurationUrl, ['Accept' => 'application/json']));
        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new OAuth2Exception("The OpenID Connect discovery document could not be fetched: HTTP $status.", $status);
        }
        $body = '';
        $stream = $response->getBody();
        while (!$stream->eof() && strlen($body) <= self::MAX_BYTES) {
            $body .= $stream->read(65536);
        }
        $data = strlen($body) > self::MAX_BYTES ? null : json_decode($body, true);
        if (!is_array($data) || array_is_list($data)) {
            throw new OAuth2Exception('The OpenID Connect discovery document is not a JSON object.');
        }
        $issuer = $data['issuer'] ?? null;
        if (!is_string($issuer) || $issuer === '') {
            throw new OAuth2Exception('The OpenID Connect discovery document names no issuer.');
        }
        $this->assertIssuer($issuer);
        return new OpenIdConfiguration(
            $issuer,
            $this->endpoint($data, 'authorization_endpoint'),
            $this->endpoint($data, 'token_endpoint'),
            self::strings($data['code_challenge_methods_supported'] ?? null),
            self::strings($data['scopes_supported'] ?? null),
        );
    }

    private function assertIssuer(string $issuer): void
    {
        if ($this->issuer !== null) {
            if ($issuer !== $this->issuer) {
                throw new OAuth2Exception('The issuer in the discovery document is not the issuer it was fetched for.');
            }
            return;
        }
        $documentHost = strtolower((string) parse_url($issuer, PHP_URL_HOST));
        $urlHost = strtolower((string) parse_url($this->configurationUrl, PHP_URL_HOST));
        if ($documentHost === '' || $documentHost !== $urlHost) {
            throw new OAuth2Exception('The issuer in the discovery document is on another host than the document.');
        }
    }

    /** @param array<mixed> $data */
    private function endpoint(array $data, string $name): ?string
    {
        $url = $data[$name] ?? null;
        if ($url === null) {
            return null;
        }
        if (!is_string($url)) {
            throw new OAuth2Exception("The discovery document has an invalid $name.");
        }
        try {
            OAuth2Endpoints::assertUrl($url, $name);
        } catch (\InvalidArgumentException $invalid) {
            throw new OAuth2Exception("The discovery document has an insecure $name.", 0, $invalid);
        }
        return $url;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private function now(): int
    {
        return $this->options->clock !== null ? ($this->options->clock)() : time();
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['configurationUrl' => $this->configurationUrl];
    }
}
