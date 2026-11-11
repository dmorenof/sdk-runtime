<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * Operation servers supported by the shared URL builder: literal absolute URLs, URLs relative to the
 * client base URL, and templates with declared variables.
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

    /** Whether the URL starts with a scheme (`https://...`, `mailto:`...), as opposed to being relative. */
    public static function isAbsolute(string $url): bool
    {
        return preg_match('/^[a-z][a-z0-9+.\-]*:/i', $url) === 1;
    }

    /**
     * Absolute server URL for a literal (absolute or relative) declaration. A relative URL is resolved
     * against the client base URL as if it were a directory (RFC 3986): `/v2` replaces the path,
     * `v2` and `../v2` are taken from the base path.
     */
    public static function resolve(string $url, string $baseUrl): string
    {
        if (self::isAbsolute($url)) {
            return self::normalize($url);
        }
        if (preg_match('/[\x00-\x20\x7f{}]/', $url)) {
            throw new \InvalidArgumentException('Relative operation servers cannot contain whitespace, control characters or template variables.');
        }
        return self::normalize((string) UriResolver::resolve(new Uri(rtrim(self::normalize($baseUrl), '/') . '/'), new Uri($url)));
    }

    /**
     * Fills a server template with declared variables. A supplied value replaces the declared default and
     * must be one of its `enum` when it declares one. Values supplied for variables this server does not
     * declare are ignored, so one set of values can serve operations with different servers.
     *
     * @param array{url:string,variables?:array<string,array{default:string,enum?:list<string>}>} $server
     * @param array<string,string> $values
     */
    public static function expand(array $server, array $values = []): string
    {
        $template = $server['url'];
        $declared = $server['variables'] ?? [];
        $authorityEnd = self::authorityEnd($template);

        return (string) preg_replace_callback('/\{([^{}]*)\}/', static function (array $match) use ($declared, $values, $authorityEnd, $template): string {
            [[$placeholder, $offset], [$name]] = $match;
            $variable = $declared[$name] ?? throw new \InvalidArgumentException("Server variable {$name} is used in $template but not declared.");
            $value = $values[$name] ?? $variable['default'];
            if (!is_string($value) || $value === '' && !isset($variable['enum'])) {
                throw new \InvalidArgumentException("Server variable {$name} needs a nonempty string.");
            }
            if (isset($variable['enum']) && !in_array($value, $variable['enum'], true)) {
                throw new \InvalidArgumentException("Server variable {$name} must be one of: " . implode(', ', $variable['enum']) . '.');
            }
            self::assertKeepsStructure($name, $value, $offset < $authorityEnd);
            return $value;
        }, $template, -1, $count, PREG_OFFSET_CAPTURE);
    }

    /** Offset where the authority (`user@host:port`) of a template ends; 0 when it has none (a relative URL). */
    private static function authorityEnd(string $template): int
    {
        if (preg_match('~^[a-z][a-z0-9+.\-]*://[^/]*~i', $template, $m) === 1) {
            return strlen($m[0]);
        }
        return str_starts_with($template, '//') ? (int) strpos($template . '/', '/', 2) : 0;
    }

    /** A variable's value may not change where the host, path, query or fragment begin. */
    private static function assertKeepsStructure(string $name, string $value, bool $inAuthority): void
    {
        if (preg_match('/[\x00-\x20\x7f?#@\\\\{}]/', $value) === 1 || ($inAuthority && str_contains($value, '/'))) {
            throw new \InvalidArgumentException("Server variable {$name} holds characters that would change the URL structure.");
        }
    }
}
