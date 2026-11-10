<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Literal operation servers supported by the shared URL builder.
 *
 * @internal Not for application code. Frozen only as the contract with sdk-generator of the same major version (see docs/PUBLIC_API.md).
 */
final class ServerUrl
{
    public static function normalize(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || !isset($parts['host']) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f{}]/', $url)) {
            throw new \InvalidArgumentException('Operation servers require literal absolute HTTP(S) URLs without credentials, query or fragment.');
        }
        return rtrim($url, '/');
    }
}
