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
     * @param array<string, array<string, mixed>> $definitions Named schemas that `reference` kinds point to.
     * @param string $mode 'neutral', 'request' (readOnly properties are skipped) or 'response' (writeOnly ones are).
     */
    public static function matches(mixed $value, array $schema, array $definitions = [], string $mode = 'neutral'): bool
    {
        return (new self($definitions, $mode))->check($value, $schema, 0);
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     */
    private function __construct(private readonly array $definitions, private readonly string $mode) {}

    /** @param array<string, mixed> $schema */
    private function check(mixed $value, array $schema, int $depth): bool
    {
        if ($depth > 256) {
            throw new \UnexpectedValueException('Schema evaluation depth exceeded.');
        }
        $kind = $schema['kind'];
        if ($kind === 'reference') {
            $name = $schema['ref'] ?? '';
            $target = $this->definitions[$name] ?? throw new \LogicException("Missing schema definition $name.");
            return $this->check($value, $target, $depth + 1);
        }
        if ($value === null && ($schema['nullable'] ?? false)) {
            return $this->satisfies(null, ($schema['rules'] ?? []) + (isset($schema['enum']) ? ['enum' => $schema['enum']] : []));
        }
        if (in_array($kind, ['allOf', 'oneOf', 'anyOf'], true)) {
            return $this->checkComposition($value, $schema, $kind, $depth);
        }
        if (!self::hasKind($value, $kind)) {
            return false;
        }
        if ((isset($schema['enum']) && !$this->satisfies($value, ['enum' => $schema['enum']])) || !$this->satisfies($value, $schema['rules'] ?? [])) {
            return false;
        }
        if ($kind === 'array' && is_array($value)) {
            return $this->checkItems($value, $schema, $depth);
        }
        if ($kind === 'object' && $value instanceof \stdClass) {
            return $this->checkObject($value, $schema, $depth);
        }
        return true;
    }

    /** @param array<string, mixed> $schema */
    private function checkComposition(mixed $value, array $schema, string $kind, int $depth): bool
    {
        if (!$this->satisfies($value, $schema['rules'] ?? [])) {
            return false;
        }
        $branches = $schema['branches'] ?? [];
        $count = 0;
        foreach ($branches as $branch) {
            if ($this->check($value, $branch, $depth + 1)) {
                $count++;
            }
        }
        return match ($kind) {
            'allOf' => $count === count($branches),
            'oneOf' => $count === 1,
            default => $count > 0,
        };
    }

    /**
     * @param array<mixed> $items
     * @param array<string, mixed> $schema
     */
    private function checkItems(array $items, array $schema, int $depth): bool
    {
        foreach ($items as $item) {
            if (!$this->check($item, $schema['items'] ?? ['kind' => 'mixed'], $depth + 1)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $schema */
    private function checkObject(\stdClass $value, array $schema, int $depth): bool
    {
        $properties = $schema['properties'] ?? [];
        $skipped = match ($this->mode) {
            'request' => $schema['readOnly'] ?? [],
            'response' => $schema['writeOnly'] ?? [],
            default => [],
        };
        $requiredSkipped = $this->mode === 'neutral' ? array_merge($schema['readOnly'] ?? [], $schema['writeOnly'] ?? []) : $skipped;
        foreach ($schema['required'] ?? [] as $name) {
            if (!in_array($name, $requiredSkipped, true) && !property_exists($value, $name)) {
                return false;
            }
        }
        foreach (get_object_vars($value) as $name => $item) {
            $name = (string) $name;
            if (isset($properties[$name])) {
                if (!in_array($name, $skipped, true) && !$this->check($item, $properties[$name], $depth + 1)) {
                    return false;
                }
                continue;
            }
            $additional = $schema['additional'] ?? true;
            if ($additional === false || (is_array($additional) && !$this->check($item, $additional, $depth + 1))) {
                return false;
            }
        }
        return true;
    }

    private static function hasKind(mixed $value, string $kind): bool
    {
        return match ($kind) {
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
    }

    /** @param array<string, mixed> $rules */
    private function satisfies(mixed $value, array $rules): bool
    {
        try {
            Constraints::validate($value, $rules, 'composition', $this->mode);
        } catch (\UnexpectedValueException) {
            return false;
        }
        return true;
    }
}
