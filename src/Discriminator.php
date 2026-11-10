<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Declarative dispatch only; schema validity is checked independently.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final class Discriminator
{
    /** @param array<string, class-string> $mapping @return class-string */
    public static function select(mixed $value, string $property, array $mapping, string $where): string
    {
        if (!$value instanceof \stdClass || !property_exists($value, $property)) {
            throw new \UnexpectedValueException("Missing discriminator property '$property' at $where.");
        }
        $tag = $value->{$property};
        if ($tag instanceof \BackedEnum) {
            $tag = $tag->value;
        }
        if (!is_string($tag) || !isset($mapping[$tag])) {
            throw new \UnexpectedValueException("Unknown discriminator value for '$property' at $where.");
        }
        return $mapping[$tag];
    }
}
