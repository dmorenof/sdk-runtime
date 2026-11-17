<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Where a model keeps the properties its schema did not declare. They live outside the object, so declared
 * properties stay the only ones PHP sees on it, and they go away with the model.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final class AdditionalPropertyStore
{
    /** @var ?\WeakMap<DTO, array<string, mixed>> */
    private static ?\WeakMap $values = null;

    /** @return array<string, mixed> */
    public static function all(DTO $dto): array
    {
        return self::$values[$dto] ?? [];
    }

    public static function put(DTO $dto, string $name, mixed $value): void
    {
        $values = self::all($dto);
        $values[$name] = $value;
        self::$values ??= new \WeakMap();
        self::$values[$dto] = $values;
    }
}
