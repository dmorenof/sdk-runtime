<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;


/** JSON decoding delegates every type conversion to the shared DTO runtime. */
final class JsonHydrator
{
    public static function decode(string $body, string $type, bool $nullable = false): mixed
    {
        return Hydrator::hydrate(
            json_decode($body, false, 512, JSON_THROW_ON_ERROR),
            $type,
            $nullable,
            'response',
        );
    }
}