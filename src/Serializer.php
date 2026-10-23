<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Turns already validated models, collections, maps and unions back into JSON-ready values.
 * The mode ('request', 'response' or 'neutral') selects which directional fields are omitted.
 */
final class Serializer
{
    public static function value(mixed $value, string $mode): mixed
    {
        if ($value instanceof DTO) {
            $effective = $mode === 'neutral'
                ? ($value instanceof Request ? 'request' : ($value instanceof Response ? 'response' : 'neutral'))
                : $mode;
            return $value->serializeValidated($effective);
        }
        if ($value instanceof TypedArray) {
            $items = [];
            foreach ($value as $key => $item) {
                $items[$key] = self::value($item, $mode);
            }
            return $items;
        }
        if ($value instanceof Map || $value instanceof AnyOf) {
            return $value->serializeValidatedForMode($mode);
        }
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::value($item, $mode), $value);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $excluded Direction-specific fields to omit.
     * @param array<string, string> $wireNames PHP property name => JSON name.
     */
    public static function fields(array $fields, array $excluded, array $wireNames, string $mode): object
    {
        $serialized = [];
        foreach ($fields as $name => $value) {
            if (!in_array($name, $excluded, true)) {
                $serialized[$wireNames[$name] ?? $name] = self::value($value, $mode);
            }
        }
        return (object) $serialized;
    }
}
