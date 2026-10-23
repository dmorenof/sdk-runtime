<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/** Shared exact-one-match semantics for generated OpenAPI oneOf wrappers. */
class OneOf extends AnyOf
{
    protected static function assertMatchCount(int $count): void
    {
        if ($count !== 1) {
            throw new \UnexpectedValueException(
                'Expected exactly one oneOf match for ' . static::class . "; found $count.",
            );
        }
    }
}