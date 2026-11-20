<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

use Psr\Http\Client\ClientInterface;

/** What has a sensible default when validating ID tokens. */
final readonly class IdTokenOptions
{
    /** Signature algorithms that can be accepted: RSASSA-PKCS1-v1_5 and ECDSA. Never `none` or a shared-secret one. */
    public const array SUPPORTED_ALGORITHMS = ['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'ES512'];

    /**
     * @param ?ClientInterface $http Used to fetch the provider's keys. Default: Guzzle.
     * @param list<string> $algorithms Algorithms accepted, from SUPPORTED_ALGORITHMS. Default: all of them.
     * @param int $leeway Seconds of clock difference tolerated in `exp`, `nbf` and `iat`.
     * @param int $keysTtl Seconds the provider's keys are reused; an unknown `kid` fetches them again at most once a minute.
     * @param (\Closure(): int)|null $clock Current Unix time; tests inject a fixed one.
     */
    public function __construct(
        public ?ClientInterface $http = null,
        public array $algorithms = self::SUPPORTED_ALGORITHMS,
        public int $leeway = 60,
        public int $keysTtl = 3600,
        public ?\Closure $clock = null,
    ) {
        if ($algorithms === [] || array_diff($algorithms, self::SUPPORTED_ALGORITHMS) !== []) {
            throw new \InvalidArgumentException('The accepted algorithms must be some of ' . implode(', ', self::SUPPORTED_ALGORITHMS) . '.');
        }
        if ($leeway < 0 || $keysTtl < 0) {
            throw new \InvalidArgumentException('The leeway and the keys time to live cannot be negative.');
        }
    }
}
