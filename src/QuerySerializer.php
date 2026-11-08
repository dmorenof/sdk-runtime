<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/** Query scalars and explicitly declared lists. Item encoding precedes CSV joining. */
final class QuerySerializer
{
    /**
     * @param array<array-key, scalar|list<scalar>|DTO|Map|\stdClass|null> $parameters
     * @param array<string, 'multi'|'csv'|'ssv'|'tsv'|'pipes'|'deepObject'> $formats Query name => normalized wire format.
     */
    public static function encode(array $parameters, array $formats = []): string
    {
        foreach ($formats as $name => $format) {
            self::assertFormat($format, (string) $name);
        }
        $pairs = [];
        foreach ($parameters as $name => $value) {
            if ($value === null) {
                continue;
            }
            $encodedName = rawurlencode((string) $name);
            if (isset($formats[$name])) {
                $format = $formats[$name];
                if ($format === 'deepObject') {
                    foreach (self::objectFields($value, (string) $name) as $key => $item) {
                        $expanded = $name . '[' . $key . ']';
                        if (array_key_exists($expanded, $parameters)) {
                            throw new \UnexpectedValueException("Deep query parameter $expanded collides with another query parameter.");
                        }
                        $pairs[] = rawurlencode($expanded) . '=' . rawurlencode(self::scalar($item));
                    }
                    continue;
                }
                $delimiter = match ($format) { 'ssv' => ' ', 'tsv' => "\t", 'pipes' => '|', default => null };
                $items = self::encodedList($value, (string) $name, $delimiter);
                if ($items === []) {
                    continue; // Empty list expansion has no URI-template members.
                }
                if ($format !== 'multi') {
                    $separator = $delimiter === null ? ',' : rawurlencode($delimiter);
                    $pairs[] = $encodedName . '=' . implode($separator, $items);
                } else {
                    foreach ($items as $item) {
                        $pairs[] = $encodedName . '=' . $item;
                    }
                }
            } else {
                if (is_array($value)) {
                    throw new \UnexpectedValueException("Query array $name requires declared serialization metadata.");
                }
                $pairs[] = $encodedName . '=' . rawurlencode(self::scalar($value));
            }
        }
        return implode('&', $pairs);
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
        if (!in_array($format, ['multi', 'csv', 'ssv', 'tsv', 'pipes', 'deepObject'], true)) {
            throw new \InvalidArgumentException("Unsupported query array format for $name.");
        }
    }

    /** @return array<array-key, scalar> */
    private static function objectFields(mixed $value, string $name): array
    {
        self::assertObjectKey($name);
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
            self::assertObjectKey((string) $key);
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

    private static function assertObjectKey(string $key): void
    {
        if ($key === '' || strpbrk($key, '[]') !== false) {
            throw new \UnexpectedValueException('Deep query parameter and property names must be non-empty and contain no brackets.');
        }
    }

    /** @return list<string> */
    private static function encodedList(mixed $value, string $name, ?string $delimiter): array
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
            $items[] = rawurlencode($text);
        }
        return $items;
    }
}
