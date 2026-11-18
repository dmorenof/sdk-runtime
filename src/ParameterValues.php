<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Reading the values a parameter can hold: finite scalars, flat objects and lists of scalars.
 * Shared by the query string and the path/header serializers.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final class ParameterValues
{
    /**
     * A parameter value as it travels: an enum case is its backing value, and so is each one in a list.
     * Anything else is returned as it is, for the serializers to accept or refuse.
     */
    public static function plain(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_array($value) && array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => $item instanceof \BackedEnum ? $item->value : $item, $value);
        }
        return $value;
    }

    public static function scalar(mixed $value): string
    {
        if (!is_scalar($value) || (is_float($value) && !is_finite($value))) {
            throw new \UnexpectedValueException('Query values must be finite, non-null scalars.');
        }
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    /** @return array<array-key, scalar> */
    public static function objectFields(mixed $value, string $name, bool $deep = true): array
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

    public static function assertObjectKey(string $key, bool $deep = true): void
    {
        if ($key === '' || ($deep && strpbrk($key, '[]') !== false)) {
            throw new \UnexpectedValueException('Deep query parameter and property names must be non-empty and contain no brackets.');
        }
    }

    /**
     * @param callable(string):string $encode
     * @return list<string>
     */
    public static function encodedList(mixed $value, string $name, ?string $delimiter, callable $encode): array
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
