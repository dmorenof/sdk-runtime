<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/** Where OAuth2 tokens are kept between requests. Implement it to share tokens across processes. */
interface TokenStoreInterface
{
    public function get(string $key): ?OAuth2Token;

    public function set(string $key, OAuth2Token $token): void;

    public function delete(string $key): void;
}
