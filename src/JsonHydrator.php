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
        $json = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        // Each model, map and collection checks itself as it is built, so the answer is not walked again.
        return DTO::readingResponse(static fn (): mixed => Hydrator::hydrate($json, $type, $nullable, 'response'));
    }
}