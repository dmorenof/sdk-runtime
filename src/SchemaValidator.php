<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Evaluates generated schema descriptors against JSON, without hydrating or dispatching classes.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final class SchemaValidator
{
    /** Make an independent JSON snapshot, preserving objects, lists and floating point numbers. */
    public static function snapshot(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, array<string, mixed>> $definitions
     */
    public static function matches(mixed $value, array $schema, array $definitions = [], string $mode = 'neutral', int $depth = 0): bool
    {
        if ($depth > 256) {
            throw new \UnexpectedValueException('Schema evaluation depth exceeded.');
        }
        /** @var array{kind:string, ref?:string, nullable?:bool, enum?:list<mixed>, rules?:array<string,mixed>, branches?:list<array<string,mixed>>, properties?:array<string,array<string,mixed>>, required?:list<string>, readOnly?:list<string>, writeOnly?:list<string>, additional?:bool|array<string,mixed>, items?:array<string,mixed>} $schema */
        $kind = $schema['kind'];
        if ($kind === 'reference') {
            $name = $schema['ref'] ?? '';
            if (!isset($definitions[$name])) {
                throw new \LogicException("Missing schema definition $name.");
            }
            return self::matches($value, $definitions[$name], $definitions, $mode, $depth + 1);
        }
        if ($value === null && ($schema['nullable'] ?? false)) {
            try { Constraints::validate(null, ($schema['rules'] ?? []) + (isset($schema['enum']) ? ['enum' => $schema['enum']] : []), 'composition', $mode); }
            catch (\UnexpectedValueException) { return false; }
            return true;
        }
        if (in_array($kind, ['allOf', 'oneOf', 'anyOf'], true)) {
            try { Constraints::validate($value, $schema['rules'] ?? [], 'composition', $mode); }
            catch (\UnexpectedValueException) { return false; }
            $count = 0;
            $branches = $schema['branches'] ?? [];
            foreach ($branches as $branch) {
                if (self::matches($value, $branch, $definitions, $mode, $depth + 1)) {
                    $count++;
                }
            }
            return match ($kind) {
                'allOf' => $count === count($branches),
                'oneOf' => $count === 1,
                default => $count > 0,
            };
        }
        $valid = match ($kind) {
            'mixed' => true,
            'null' => $value === null,
            'string' => is_string($value),
            'integer' => is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'object' => $value instanceof \stdClass,
            'array' => is_array($value) && array_is_list($value),
            default => throw new \LogicException("Unknown schema kind $kind."),
        };
        if (!$valid) {
            return false;
        }
        if (isset($schema['enum'])) {
            try { Constraints::validate($value, ['enum' => $schema['enum']], 'composition', $mode); }
            catch (\UnexpectedValueException) { return false; }
        }
        try {
            Constraints::validate($value, $schema['rules'] ?? [], 'composition', $mode);
        } catch (\UnexpectedValueException) {
            return false;
        }
        if ($kind === 'array' && is_array($value)) {
            foreach ($value as $item) {
                if (!self::matches($item, $schema['items'] ?? ['kind' => 'mixed'], $definitions, $mode, $depth + 1)) {
                    return false;
                }
            }
        }
        if ($kind === 'object' && $value instanceof \stdClass) {
            $properties = $schema['properties'] ?? [];
            $skipped = $mode === 'request' ? ($schema['readOnly'] ?? [])
                : ($mode === 'response' ? ($schema['writeOnly'] ?? []) : []);
            $requiredSkipped = $mode === 'neutral' ? array_merge($schema['readOnly'] ?? [], $schema['writeOnly'] ?? []) : $skipped;
            foreach ($schema['required'] ?? [] as $name) {
                if (!in_array($name, $requiredSkipped, true) && !property_exists($value, $name)) {
                    return false;
                }
            }
            foreach (get_object_vars($value) as $name => $item) {
                $name = (string) $name;
                if (isset($properties[$name])) {
                    if (!in_array($name, $skipped, true) && !self::matches($item, $properties[$name], $definitions, $mode, $depth + 1)) {
                        return false;
                    }
                } else {
                    $additional = $schema['additional'] ?? true;
                    if ($additional === false || (is_array($additional) && !self::matches($item, $additional, $definitions, $mode, $depth + 1))) {
                        return false;
                    }
                }
            }
        }
        return true;
    }
}
