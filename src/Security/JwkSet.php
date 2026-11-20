<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;

/**
 * The public keys a provider publishes at its `jwks_uri` (RFC 7517), kept for a while and fetched again when a token
 * names a key that is not there yet (a key rotation), at most once a minute so a stream of forged `kid`s cannot make
 * this hit the provider.
 *
 * @internal Implementation detail of IdTokenValidator: not covered by the backward-compatibility promise.
 */
final class JwkSet
{
    private const int MAX_BYTES = 1048576;
    private const int REFETCH_COOLDOWN = 60;
    private const int MAX_KEYS = 100;

    private readonly ClientInterface $http;
    /** @var list<array<mixed>>|null */
    private ?array $keys = null;
    private int $fetchedAt = 0;

    public function __construct(private readonly string $uri, private readonly IdTokenOptions $options)
    {
        OAuth2Endpoints::assertUrl($uri, 'jwks_uri');
        $this->http = $options->http ?? new \GuzzleHttp\Client(['http_errors' => false, 'timeout' => 15, 'connect_timeout' => 5]);
    }

    /**
     * The keys with this `kid`, or all of them when the token names none.
     *
     * @return list<array<mixed>>
     * @throws OAuth2Exception The keys could not be fetched.
     */
    public function find(?string $kid): array
    {
        $now = $this->now();
        $fresh = false;
        if ($this->keys === null || $now - $this->fetchedAt >= $this->options->keysTtl) {
            $this->fetch($now);
            $fresh = true;
        }
        $found = $this->matching($kid);
        if ($found === [] && $kid !== null && !$fresh && $now - $this->fetchedAt >= self::REFETCH_COOLDOWN) {
            $this->fetch($now);
            $found = $this->matching($kid);
        }
        return $found;
    }

    /** @return list<array<mixed>> */
    private function matching(?string $kid): array
    {
        return array_values(array_filter($this->keys ?? [], static fn(array $key): bool => $kid === null || ($key['kid'] ?? null) === $kid));
    }

    private function fetch(int $now): void
    {
        $response = $this->http->sendRequest(new Request('GET', $this->uri, ['Accept' => 'application/json']));
        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new OAuth2Exception("The provider's signing keys could not be fetched: HTTP $status.", $status);
        }
        $body = '';
        $stream = $response->getBody();
        while (!$stream->eof() && strlen($body) <= self::MAX_BYTES) {
            $body .= $stream->read(65536);
        }
        $data = strlen($body) > self::MAX_BYTES ? null : json_decode($body, true);
        $list = is_array($data) && is_array($data['keys'] ?? null) && array_is_list($data['keys']) ? $data['keys'] : null;
        if ($list === null || count($list) > self::MAX_KEYS) {
            throw new OAuth2Exception("The provider's signing keys are not a JWK Set.");
        }
        $this->keys = array_values(array_filter($list, static fn(mixed $key): bool => is_array($key) && !array_is_list($key)));
        $this->fetchedAt = $now;
    }

    private function now(): int
    {
        return $this->options->clock !== null ? ($this->options->clock)() : time();
    }
}
