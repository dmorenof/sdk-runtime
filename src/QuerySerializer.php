<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

use GuzzleHttp\Psr7\Query;

/** Query scalars and explicitly declared lists. Item encoding precedes CSV joining. */
final class QuerySerializer
{
    /**
     * @param array<array-key, scalar|list<scalar>|DTO|Map|\stdClass|null> $parameters
     * @param array<array-key, 'multi'|'csv'|'ssv'|'tsv'|'pipes'|'deepObject'|'form-object'|'form-object-explode'> $formats Query name => normalized wire format.
     */
    public static function encode(array $parameters, array $formats = [], int $encoding = PHP_QUERY_RFC3986): string
    {
        $encode = match ($encoding) {
            PHP_QUERY_RFC3986 => rawurlencode(...),
            PHP_QUERY_RFC1738 => urlencode(...),
            default => throw new \InvalidArgumentException('Unsupported query percent-encoding standard.'),
        };
        foreach ($formats as $name => $format) {
            self::assertFormat($format, (string) $name);
        }
        $pairs = [];
        foreach ($parameters as $name => $value) {
            if ($value === null) {
                continue;
            }
            $encodedName = $encode((string) $name);
            if (isset($formats[$name])) {
                $format = $formats[$name];
                if (in_array($format, ['form-object', 'form-object-explode'], true)) {
                    $items = [];
                    foreach (self::objectFields($value, (string) $name, deep: false) as $key => $item) {
                        $encodedKey = $encode((string) $key);
                        if ($format === 'form-object-explode') {
                            if (((string) $key !== (string) $name && array_key_exists($key, $parameters)) || array_key_exists($encodedKey, $pairs)) {
                                throw new \UnexpectedValueException("Expanded query property $key collides with another parameter.");
                            }
                            $pairs[$encodedKey] = $encode(self::scalar($item));
                        } else {
                            $items[] = $encodedKey;
                            $items[] = $encode(self::scalar($item));
                        }
                    }
                    if ($items !== []) {
                        $pairs[$encodedName] = implode(',', $items);
                    }
                    continue;
                }
                if ($format === 'deepObject') {
                    foreach (self::objectFields($value, (string) $name) as $key => $item) {
                        $expanded = $name . '[' . $key . ']';
                        if (array_key_exists($expanded, $parameters)) {
                            throw new \UnexpectedValueException("Deep query parameter $expanded collides with another query parameter.");
                        }
                        $pairs[$encode($expanded)] = $encode(self::scalar($item));
                    }
                    continue;
                }
                $delimiter = match ($format) { 'ssv' => ' ', 'tsv' => "\t", 'pipes' => '|', default => null };
                $items = self::encodedList($value, (string) $name, $delimiter, $encode);
                if ($items === []) {
                    continue; // Empty list expansion has no URI-template members.
                }
                if ($format !== 'multi') {
                    $separator = $delimiter === null ? ',' : $encode($delimiter);
                    $pairs[$encodedName] = implode($separator, $items);
                } else {
                    $pairs[$encodedName] = $items;
                }
            } else {
                if (is_array($value)) {
                    throw new \UnexpectedValueException("Query array $name requires declared serialization metadata.");
                }
                $pairs[$encodedName] = $encode(self::scalar($value));
            }
        }
        return Query::build($pairs, false);
    }

    /** Primitive simple lists reuse the query list validation and native item encoding. */
    public static function simpleList(mixed $value, string $name, bool $header = false): string
    {
        $encode = $header ? self::headerText(...) : rawurlencode(...);
        return implode(',', self::encodedList($value, $name, $header ? ',' : null, $encode));
    }

    /** Existing metadata keeps csv; object forms use the same flat DTO/Map extraction. */
    public static function simple(mixed $value, string $name, string $format, bool $header = false): string
    {
        if ($format === 'csv') {
            return self::simpleList($value, $name, $header);
        }
        if (!in_array($format, ['object', 'object-explode'], true)) {
            throw new \InvalidArgumentException('Unsupported simple parameter format.');
        }
        $explode = $format === 'object-explode';
        $encode = $header ? self::headerText(...) : rawurlencode(...);
        $items = [];
        foreach (self::objectFields($value, $name, deep: false) as $key => $item) {
            $key = (string) $key;
            $text = self::scalar($item);
            if ($header && (str_contains($key, ',') || str_contains($text, ',')
                || ($explode && str_contains($key, '=')))) {
                throw new \UnexpectedValueException('Simple header object contains its structural delimiter.');
            }
            if ($explode) {
                $items[] = $encode($key) . '=' . $encode($text);
            } else {
                $items[] = $encode($key);
                $items[] = $encode($text);
            }
        }
        return implode(',', $items);
    }

    /** Expand one declared path parameter, not an arbitrary URI-template language. */
    public static function path(mixed $value, string $name, string $format): string
    {
        if (in_array($format, ['csv', 'object', 'object-explode'], true)) {
            return self::simple($value, $name, $format);
        }
        if (preg_match('/^(label|matrix)(?:-(list|object)(-explode)?)?$/D', $format, $matches) !== 1) {
            throw new \InvalidArgumentException('Unsupported path parameter format.');
        }
        $label = $matches[1] === 'label';
        $kind = $matches[2] ?? 'scalar';
        $explode = isset($matches[3]);
        $named = static fn (string $key, string $text): string => ';' . $key . ($text === '' ? '' : '=' . $text);
        if ($kind === 'scalar') {
            $text = rawurlencode(self::scalar($value));
            return $label ? '.' . $text : $named(rawurlencode($name), $text);
        }
        if ($kind === 'list') {
            $items = self::encodedList($value, $name, null, rawurlencode(...));
            if ($items === []) { return ''; }
            if ($label) { return '.' . implode($explode ? '.' : ',', $items); }
            if (!$explode) { return $named(rawurlencode($name), implode(',', $items)); }
            return implode('', array_map(static fn (string $item): string => $named(rawurlencode($name), $item), $items));
        }
        $items = [];
        foreach (self::objectFields($value, $name, deep: false) as $key => $item) {
            $key = rawurlencode((string) $key); $text = rawurlencode(self::scalar($item));
            if (!$explode) { $items[] = $key; $items[] = $text; }
            else { $items[] = $label ? $key . '=' . $text : $named($key, $text); }
        }
        if ($items === []) { return ''; }
        if ($label) { return '.' . implode($explode ? '.' : ',', $items); }
        return $explode ? implode('', $items) : $named(rawurlencode($name), implode(',', $items));
    }

    private static function headerText(string $text): string
    {
        if ($text !== trim($text, " \t") || preg_match('/^[\x09\x20-\x7E\x80-\xFF]*$/D', $text) !== 1) {
            throw new \UnexpectedValueException('Simple header items must preserve HTTP field whitespace and contain valid header bytes.');
        }
        return $text;
    }

    private static function scalar(mixed $value): string
    {
        if (!is_scalar($value) || (is_float($value) && !is_finite($value))) {
            throw new \UnexpectedValueException('Query values must be finite, non-null scalars.');
        }
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    private static function assertFormat(mixed $format, string $name): void
    {
        if (!in_array($format, ['multi', 'csv', 'ssv', 'tsv', 'pipes', 'deepObject', 'form-object', 'form-object-explode'], true)) {
            throw new \InvalidArgumentException("Unsupported query array format for $name.");
        }
    }

    /** @return array<array-key, scalar> */
    private static function objectFields(mixed $value, string $name, bool $deep = true): array
    {
        self::assertObjectKey($name, $deep);
        if ($value instanceof DTO) {
            $value = $value->jsonSerializeForRequest();
        }
        if ($value instanceof Map) {
            $value = $value->serializeForMode('request');
        }
        if (!$value instanceof \stdClass) {
            throw new \UnexpectedValueException("Expected a flat object for deep query parameter $name.");
        }
        $fields = [];
        foreach (get_object_vars($value) as $key => $item) {
            self::assertObjectKey((string) $key, $deep);
            if ($item instanceof \BackedEnum) {
                $item = $item->value;
            }
            self::scalar($item); // Null, nested values and non-finite numbers have no supported expansion.
            if (!is_scalar($item)) {
                throw new \UnexpectedValueException("Expected a scalar property at $name.$key.");
            }
            $fields[$key] = $item;
        }
        return $fields;
    }

    private static function assertObjectKey(string $key, bool $deep = true): void
    {
        if ($key === '' || ($deep && strpbrk($key, '[]') !== false)) {
            throw new \UnexpectedValueException('Deep query parameter and property names must be non-empty and contain no brackets.');
        }
    }

    /**
     * @param callable(string):string $encode
     * @return list<string>
     */
    private static function encodedList(mixed $value, string $name, ?string $delimiter, callable $encode): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("Expected a list for query parameter $name.");
        }
        $items = [];
        foreach ($value as $item) {
            $text = self::scalar($item);
            if ($delimiter !== null && str_contains($text, $delimiter)) {
                throw new \UnexpectedValueException("Query parameter $name contains its list delimiter; use a different serialization.");
            }
            $items[] = $encode($text);
        }
        return $items;
    }
}
