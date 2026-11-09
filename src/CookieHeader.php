<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/** Request-cookie assembly only: no cookie jar, response parser or persistence. */
final class CookieHeader
{
    public static function pair(string $name, string $value): string
    {
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name) !== 1) {
            throw new \UnexpectedValueException('Cookie name must be an HTTP token.');
        }
        $unquoted = str_starts_with($value, '"') && str_ends_with($value, '"') && strlen($value) >= 2
            ? substr($value, 1, -1) : $value;
        if (preg_match('/^[\x21\x23-\x2B\x2D-\x3A\x3C-\x5B\x5D-\x7E]*$/D', $unquoted) !== 1) {
            throw new \UnexpectedValueException('Cookie value contains bytes forbidden by RFC 6265.');
        }
        return $name . '=' . $value;
    }

    /**
     * Merge Cookie fields case-insensitively; cookie names remain case-sensitive.
     * Other headers retain the existing array_replace precedence.
     * @param array<string,string> ...$sets
     * @return array<string,string>
     */
    public static function merge(array ...$sets): array
    {
        $headers = [];
        $cookies = [];
        foreach ($sets as $set) {
            foreach ($set as $name => $value) {
                if (strcasecmp((string) $name, 'Cookie') !== 0) {
                    $headers[$name] = $value;
                    continue;
                }
                if ($value === '') { continue; }
                foreach (explode(';', $value) as $part) {
                    $pair = explode('=', ltrim($part, ' '), 2);
                    if (count($pair) !== 2) {
                        throw new \UnexpectedValueException('Malformed request Cookie header.');
                    }
                    [$key, $text] = $pair;
                    self::pair($key, $text);
                    if (array_key_exists($key, $cookies) && $cookies[$key] !== $text) {
                        throw new \UnexpectedValueException('Conflicting values for request cookie ' . $key . '.');
                    }
                    $cookies[$key] = $text;
                }
            }
        }
        if ($cookies !== []) {
            $pairs = [];
            foreach ($cookies as $name => $value) { $pairs[] = (string) $name . '=' . $value; }
            $headers['Cookie'] = implode('; ', $pairs);
        }
        return $headers;
    }
}
