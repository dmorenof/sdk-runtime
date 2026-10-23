<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Converts decoded JSON values into the PHP types a model declares.
 * One entry point serves properties, collection items, map values and union branches.
 */
final class Hydrator
{
    /** @var array<class-string, array<string, \ReflectionNamedType>> */
    private static array $propertyTypes = [];

    public static function hydrate(mixed $value, string $kind, bool $nullable, string $where): mixed
    {
        $kind = match ($kind) {
            'integer' => 'int', 'double' => 'float', 'boolean' => 'bool',
            default => ltrim($kind, '\\'),
        };
        $builtin = in_array($kind, ['mixed', 'int', 'float', 'string', 'bool', 'null'], true);
        return self::convert($value, $kind, $nullable, $builtin, $where);
    }

    /** Hydrate a JSON value into the declared type of a public model property. */
    public static function hydrateProperty(DTO $model, string $property, mixed $value, string $where): mixed
    {
        $type = self::$propertyTypes[$model::class][$property] ?? null;
        if ($type === null) {
            $reflection = new \ReflectionProperty($model, $property);
            if (!$reflection->isPublic() || $reflection->isStatic()) {
                throw new \InvalidArgumentException('JSON property ' . $model::class . "::$property is not writable.");
            }
            $type = $reflection->getType();
            if (!$type instanceof \ReflectionNamedType) {
                throw new \InvalidArgumentException('Unsupported PHP property type at ' . $model::class . "::$property.");
            }
            self::$propertyTypes[$model::class][$property] = $type;
        }
        return self::convert($value, $type->getName(), $type->allowsNull(), $type->isBuiltin(), $where);
    }

    private static function convert(
        mixed $value,
        string $kind,
        bool $nullable,
        bool $builtin,
        string $where,
    ): mixed {
        if (!$builtin && is_subclass_of($kind, AnyOf::class)) {
            if ($value instanceof $kind) {
                return $value;
            }
            return $kind::fromValue($value);
        }

        if ($value === null) {
            if (!$nullable && $kind !== 'mixed' && $kind !== 'null') {
                throw new \UnexpectedValueException("Unexpected null at $where.");
            }
            return null;
        }

        if ($builtin) {
            return self::scalar($value, $kind, $where);
        }

        if (is_subclass_of($kind, Map::class)) {
            if ($value instanceof $kind) {
                return $value;
            }
            if (!is_object($value) && !is_array($value)) {
                throw new \UnexpectedValueException("Expected JSON object at $where.");
            }
            return new $kind($value);
        }

        if (is_subclass_of($kind, \BackedEnum::class)) {
            if ($value instanceof $kind) {
                return $value;
            }
            if (!is_string($value) && !is_int($value)) {
                throw new \UnexpectedValueException("Invalid enum value at $where.");
            }
            return $kind::from($value);
        }

        if (is_subclass_of($kind, TypedArray::class)) {
            return self::collection($value, $kind, $where);
        }

        if (is_subclass_of($kind, DTO::class)) {
            if ($value instanceof $kind) {
                return $value;
            }
            if (!is_object($value)) {
                throw new \UnexpectedValueException("Expected JSON object at $where.");
            }
            return new $kind($value);
        }

        throw new \UnexpectedValueException("Unsupported nested PHP type $kind at $where.");
    }

    private static function scalar(mixed $value, string $kind, string $where): mixed
    {
        if ($kind === 'mixed') {
            return $value;
        }
        // JSON integers are valid floating point values when expected.
        if ($kind === 'float' && is_int($value)) {
            return (float) $value;
        }
        $valid = match ($kind) {
            'int' => is_int($value),
            'float' => is_float($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            default => false,
        };
        if (!$valid) {
            throw new \UnexpectedValueException("Invalid scalar value at $where: expected $kind.");
        }
        return $value;
    }

    /** @param class-string<TypedArray> $kind */
    private static function collection(mixed $value, string $kind, string $where): TypedArray
    {
        if ($value instanceof $kind) {
            return $value;
        }
        // Some APIs send an empty object where an empty list is meant.
        if (is_object($value) && get_object_vars($value) === []) {
            $value = [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("Expected a JSON array at $where.");
        }
        $result = new $kind();
        $expectedType = $result->getExpectedType();
        foreach ($value as $index => $item) {
            $result->append(self::hydrate($item, $expectedType, false, $where . "[$index]"));
        }
        return $result;
    }
}
