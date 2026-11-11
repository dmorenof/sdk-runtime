<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Media type matching for representation selection. Declared types may be exact
 * (`application/json`, `application/vnd.api+json`), a structured-suffix range
 * (`application/*+json`), a type range (`text/*`) or `*` / `*` + `/` + `*`. Parameters
 * (charset, version...) are ignored on both sides.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final class MediaType
{
    private const string TOKEN = "[!#$%&'*+.^_`|\~a-z0-9-]+"; // the ~ is escaped: patterns here use it as delimiter

    /** Lower-cased `type/subtype` without parameters, or null when it is not a media type. */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $base = strtolower(trim(explode(';', $value, 2)[0]));
        return preg_match('~^' . self::TOKEN . '/' . self::TOKEN . '$~D', $base) === 1 ? $base : null;
    }

    /** 0 = no match; larger is more specific: exact 4, suffix range 3, type range 2, any 1. */
    public static function specificity(string $declared, string $actual): int
    {
        $declared = self::normalize($declared);
        $actual = self::normalize($actual);
        if ($declared === null || $actual === null) {
            return 0;
        }
        if ($declared === $actual) {
            return 4;
        }
        [$dType, $dSub] = explode('/', $declared, 2);
        [$aType, $aSub] = explode('/', $actual, 2);
        if ($dType === '*') {
            return $dSub === '*' ? 1 : 0;
        }
        if ($dType !== $aType) {
            return 0;
        }
        if ($dSub === '*') {
            return 2;
        }
        if (str_starts_with($dSub, '*+')) {
            return str_ends_with($aSub, substr($dSub, 1)) ? 3 : 0;
        }
        return 0;
    }

    /**
     * The declared entry that best matches the received Content-Type (ties keep declaration order).
     * Without a Content-Type the first declared entry is used. Null when nothing matches.
     *
     * @param list<string> $declared
     */
    public static function select(?string $contentType, array $declared): ?string
    {
        if ($declared === []) {
            return null;
        }
        $actual = self::normalize($contentType);
        if ($actual === null) {
            return trim((string) $contentType) === '' ? $declared[0] : null;
        }
        $best = null;
        $bestScore = 0;
        foreach ($declared as $candidate) {
            $score = self::specificity($candidate, $actual);
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }
        return $best;
    }

    /** `application/json` and structured-suffix types such as `application/problem+json` (parameters ignored). */
    public static function isJson(string $mediaType): bool
    {
        $base = self::normalize($mediaType);
        return $base !== null && preg_match('~^application/(?:[a-z0-9!#$&^_.+\-]+\+)?json$~D', $base) === 1;
    }

    /**
     * Where a type sits in an Accept header: JSON first, then text, then bytes, then anything else, and
     * ranges last. Equal preferences keep their declaration order.
     */
    public static function preference(string $mediaType): int
    {
        if (self::isRange($mediaType)) {
            return 4;
        }
        return self::isJson($mediaType) ? 0 : match ($mediaType) {
            'text/plain' => 1,
            'application/octet-stream' => 2,
            default => 3,
        };
    }

    /** Media type implied by a representation that does not name one. */
    public static function defaultFor(?string $type, ?string $encoding): string
    {
        return match ($encoding) {
            'jsonl' => 'application/jsonl',
            'json-seq' => 'application/json-seq',
            'sse' => 'text/event-stream',
            default => ltrim((string) $type, '\\') === \Psr\Http\Message\ResponseInterface::class ? 'application/octet-stream' : 'application/json',
        };
    }

    public static function isRange(string $declared): bool
    {
        return str_contains($declared, '*');
    }
}
