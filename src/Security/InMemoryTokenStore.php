<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

final class InMemoryTokenStore implements TokenStoreInterface
{
    /** @var array<string, OAuth2Token> */
    private array $tokens = [];

    public function get(string $key): ?OAuth2Token
    {
        return $this->tokens[$key] ?? null;
    }

    public function set(string $key, OAuth2Token $token): void
    {
        $this->tokens[$key] = $token;
    }

    public function delete(string $key): void
    {
        unset($this->tokens[$key]);
    }
}
