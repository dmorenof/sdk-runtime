<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/**
 * Proof Key for Code Exchange (RFC 7636), S256 method only.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final class Pkce
{
    /** Random verifier of 43 to 128 unreserved characters (32 to 96 random bytes, base64url). */
    public static function verifier(int $bytes = 64): string
    {
        if ($bytes < 32 || $bytes > 96) {
            throw new \InvalidArgumentException('A PKCE verifier needs 32 to 96 random bytes.');
        }
        return self::base64Url(random_bytes($bytes));
    }

    public static function challenge(string $verifier): string
    {
        if (!self::isValidVerifier($verifier)) {
            throw new \InvalidArgumentException('A PKCE verifier must be 43 to 128 characters from A-Z a-z 0-9 - . _ ~.');
        }
        return self::base64Url(hash('sha256', $verifier, true));
    }

    public static function isValidVerifier(string $verifier): bool
    {
        return preg_match('/^[A-Za-z0-9\-._~]{43,128}$/D', $verifier) === 1;
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
