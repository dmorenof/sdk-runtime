<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/**
 * A credential whose access token is obtained on demand (for example OAuth2).
 * Pass an implementation wherever a bearer token string is accepted.
 */
interface TokenProviderInterface
{
    /** Returns a currently valid access token, obtaining or renewing it when needed. */
    public function accessToken(): string;

    /** Drops the cached token so the next accessToken() call obtains a fresh one. */
    public function invalidate(): void;
}
