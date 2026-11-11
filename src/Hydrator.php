<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Converts decoded JSON values into the PHP types a model declares.
 * One entry point serves properties, collection items, map values and union branches.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final class Hydrator
{
    /** @var array<class-string, array<string, \ReflectionNamedType|\ReflectionUnionType>> */
    private static array $propertyTypes = [];

    public static function hydrate(mixed $value, string $kind, bool $nullable, string $where): mixed
    {
        if ($kind === 'stream') {
            if ($value === null && $nullable) { return null; }
            if (is_string($value)) { return $value; }
            // Non-seekable streams are sent once: retries, redirects and token replays need to rewind, so they skip them.
            if ($value instanceof \Psr\Http\Message\StreamInterface && $value->isReadable()) { return $value; }
            throw new \UnexpectedValueException("Expected binary string or readable PSR stream at $where.");
        }
        if (str_contains($kind, '|')) { return self::nativeUnion($value, explode('|', $kind), $nullable, $where); }
        $kind = match ($kind) {
            'integer' => 'int', 'double' => 'float', 'boolean' => 'bool',
            default => ltrim($kind, '\\'),
        };
        if ($kind === 'binary') {
            return self::hydrate($value, is_string($value) ? 'string' : \Psr\Http\Message\UploadedFileInterface::class, $nullable, $where);
        }
        $builtin = in_array($kind, ['mixed', 'int', 'float', 'string', 'bool', 'null', 'array'], true);
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
            if (!$type instanceof \ReflectionNamedType && !$type instanceof \ReflectionUnionType) {
                throw new \InvalidArgumentException('Unsupported PHP property type at ' . $model::class . "::$property.");
            }
            self::$propertyTypes[$model::class][$property] = $type;
        }
        if ($type instanceof \ReflectionUnionType) {
            $names = [];
            foreach ($type->getTypes() as $branch) {
                if (!$branch instanceof \ReflectionNamedType) {
                    throw new \InvalidArgumentException("Unsupported PHP property type at $where.");
                }
                $names[] = $branch->getName();
            }
            if (array_diff($names, ['string', 'int', 'float', 'bool', 'null', 'array', \stdClass::class]) === []) {
                return self::nativeUnion($value, $names, $type->allowsNull(), $where);
            }
            $stream = \Psr\Http\Message\StreamInterface::class;
            if (array_diff($names, ['string', $stream, 'null']) === [] && in_array('string', $names, true) && in_array($stream, $names, true)) {
                return self::hydrate($value, 'stream', $type->allowsNull(), $where);
            }
            $upload = \Psr\Http\Message\UploadedFileInterface::class;
            if (array_diff($names, ['string', $upload, 'null']) !== []
                || !in_array('string', $names, true) || !in_array($upload, $names, true)) {
                throw new \InvalidArgumentException("Unsupported PHP property type at $where.");
            }
            return self::hydrate($value, is_string($value) ? 'string' : $upload, $type->allowsNull(), $where);
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

        if ($kind === \Psr\Http\Message\UploadedFileInterface::class) {
            if (!$value instanceof \Psr\Http\Message\UploadedFileInterface) {
                throw new \UnexpectedValueException("Expected uploaded file at $where.");
            }
            return $value;
        }

        if ($kind === \stdClass::class) {
            if (!$value instanceof \stdClass) { throw new \UnexpectedValueException("Expected JSON object at $where."); }
            return $value;
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
            if (is_float($value) && (new \ReflectionEnum($kind))->getBackingType()?->getName() === 'int') {
                $value = self::scalar($value, 'int', $where);
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
            if (defined($kind . '::DISCRIMINATOR_BASE') && $kind::DISCRIMINATOR_BASE === $kind) {
                /** @var string $property */
                $property = $kind::DISCRIMINATOR_PROPERTY;
                /** @var array<string, class-string> $mapping */
                $mapping = $kind::DISCRIMINATOR_MAPPING;
                $selected = Discriminator::select($value, $property, $mapping, $where);
                if (!is_a($selected, $kind, true)) {
                    throw new \LogicException("Discriminator target $selected must extend $kind.");
                }
                return new $selected($value);
            }
            return new $kind($value);
        }

        throw new \UnexpectedValueException("Unsupported nested PHP type $kind at $where.");
    }

    /** @param list<string> $types */
    private static function nativeUnion(mixed $value, array $types, bool $nullable, string $where): mixed
    {
        $types = array_map(static fn (string $type): string => ltrim($type, '\\'), $types);
        if (array_diff($types, ['string', 'int', 'integer', 'float', 'number', 'bool', 'boolean', 'null', 'array', \stdClass::class]) !== []) {
            throw new \InvalidArgumentException("Unsupported union type at $where.");
        }
        if ($value === null && ($nullable || in_array('null', $types, true))) { return null; }
        $preferred = match (true) { is_int($value) => 'int', is_float($value) => 'float', is_string($value) => 'string', is_bool($value) => 'bool', is_array($value) => 'array', $value instanceof \stdClass => \stdClass::class, default => null };
        if ($preferred !== null && in_array($preferred, $types, true)) { return self::hydrate($value, $preferred, false, $where); }
        foreach ($types as $type) {
            try { return self::hydrate($value, $type, false, $where); }
            catch (\UnexpectedValueException) {}
        }
        throw new \UnexpectedValueException("No native union branch accepts $where.");
    }

    private static function scalar(mixed $value, string $kind, string $where): mixed
    {
        if ($kind === 'mixed') {
            return $value;
        }
        if ($kind === 'int' && is_float($value) && is_finite($value) && floor($value) === $value
            && $value >= PHP_INT_MIN && $value < (float) PHP_INT_MAX) {
            return (int) $value;
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
            'array' => is_array($value) && array_is_list($value),
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
