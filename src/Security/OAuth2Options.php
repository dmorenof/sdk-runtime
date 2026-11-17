<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

use Psr\Http\Client\ClientInterface;

/**
 * Everything about an OAuth2 provider that has a sensible default.
 *
 * Tokens live in memory unless a store is given: pass a shared store to keep them between processes. When the
 * store is shared by several users, give each user a distinct `$subject`.
 */
final readonly class OAuth2Options
{
    /**
     * @param list<string> $scopes Requested scopes (no whitespace, quotes or backslashes).
     * @param ?ClientInterface $http Used for token requests. Default: Guzzle.
     * @param ?TokenStoreInterface $store Where tokens are kept. Default: in memory.
     * @param string $subject Identifies the end user inside a shared store (authorization code flow).
     * @param int $leeway Seconds before expiry at which a token already counts as stale.
     * @param (\Closure(): int)|null $clock Current Unix time; tests inject a fixed one.
     */
    public function __construct(
        public array $scopes = [],
        public ?ClientInterface $http = null,
        public ?TokenStoreInterface $store = null,
        public string $subject = '',
        public int $leeway = 30,
        public ?\Closure $clock = null,
    ) {
        /** @var array<mixed> $unchecked Checked here: the list is typed, but callers may pass anything. */
        $unchecked = $scopes;
        foreach ($unchecked as $scope) {
            if (!is_string($scope) || $scope === '' || preg_match('/[\s"\\\\]/', $scope) === 1) {
                throw new \InvalidArgumentException('Invalid OAuth2 scope.');
            }
        }
        if ($leeway < 0) {
            throw new \InvalidArgumentException('OAuth2 leeway cannot be negative.');
        }
    }
}
