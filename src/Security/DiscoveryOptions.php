<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

use Psr\Http\Client\ClientInterface;

/** What has a sensible default when fetching an OpenID Connect discovery document. */
final readonly class DiscoveryOptions
{
    /**
     * @param ?ClientInterface $http Used to fetch the document. Default: Guzzle.
     * @param int $ttl Seconds the document is reused before it is fetched again.
     * @param (\Closure(): int)|null $clock Current Unix time; tests inject a fixed one.
     */
    public function __construct(
        public ?ClientInterface $http = null,
        public int $ttl = 3600,
        public ?\Closure $clock = null,
    ) {
        if ($ttl < 0) {
            throw new \InvalidArgumentException('The discovery time to live cannot be negative.');
        }
    }
}
