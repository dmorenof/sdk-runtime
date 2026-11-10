<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

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

    /**
     * Absolute server URL for a literal (absolute or relative) declaration. A relative URL is resolved
     * against the client base URL as if it were a directory (RFC 3986): `/v2` replaces the path,
     * `v2` and `../v2` are taken from the base path.
     */
    public static function resolve(string $url, string $baseUrl): string
    {
        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $url) === 1) {
            return self::normalize($url);
        }
        if (preg_match('/[\x00-\x20\x7f{}]/', $url)) {
            throw new \InvalidArgumentException('Relative operation servers cannot contain whitespace, control characters or template variables.');
        }
        return self::normalize((string) \GuzzleHttp\Psr7\UriResolver::resolve(
            new \GuzzleHttp\Psr7\Uri(rtrim(self::normalize($baseUrl), '/') . '/'),
            new \GuzzleHttp\Psr7\Uri($url),
        ));
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
        $authorityEnd = preg_match('~^[a-z][a-z0-9+.\-]*://[^/]*~i', $template, $m) === 1 ? strlen($m[0])
            : (str_starts_with($template, '//') ? strpos($template . '/', '/', 2) : 0);

        return (string) preg_replace_callback('/\{([^{}]*)\}/', static function (array $match) use ($declared, $values, $authorityEnd, $template): string {
            $name = $match[1];
            $variable = $declared[$name] ?? throw new \InvalidArgumentException("Server variable {$name} is used in $template but not declared.");
            $value = $values[$name] ?? $variable['default'];
            if (!is_string($value) || $value === '' && !isset($variable['enum'])) {
                throw new \InvalidArgumentException("Server variable {$name} needs a nonempty string.");
            }
            if (isset($variable['enum']) && !in_array($value, $variable['enum'], true)) {
                throw new \InvalidArgumentException("Server variable {$name} must be one of: " . implode(', ', $variable['enum']) . '.');
            }
            $inAuthority = strpos($template, $match[0]) < $authorityEnd;
            if (preg_match('/[\x00-\x20\x7f?#@\\\\{}]/', $value) === 1 || ($inAuthority && str_contains($value, '/'))) {
                throw new \InvalidArgumentException("Server variable {$name} holds characters that would change the URL structure.");
            }
            return $value;
        }, $template);
    }
}
