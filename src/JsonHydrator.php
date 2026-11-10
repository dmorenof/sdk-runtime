<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;


/**
 * JSON decoding delegates every type conversion to the shared DTO runtime.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final class JsonHydrator
{
    public static function decode(string $body, string $type, bool $nullable = false): mixed
    {
        $result = Hydrator::hydrate(
            json_decode($body, false, 512, JSON_THROW_ON_ERROR),
            $type,
            $nullable,
            'response',
        );
        // Input and output types are both DTOs. The wire direction—not the
        // class hierarchy—determines readOnly/writeOnly requiredness.
        DTO::validateNestedValue($result, 'response');
        return $result;
    }
}