<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Declarative value rules (pattern, enum, length, range, item count) taken from the API contract.
 */
final class Constraints
{
    /** @param array<string, mixed> $rules */
    public static function validate(mixed $value, array $rules, string $where): void
    {
        foreach ($rules as $rule => $limit) {
            self::assert($value, $rule, $limit, $where);
        }
    }

    private static function assert(mixed $value, string $rule, mixed $limit, string $where): void
    {
        if ($rule === 'pattern') {
            if (!is_string($limit) || !is_string($value)) {
                throw new \UnexpectedValueException("Expected string for pattern at $where.");
            }
            $regex = '~' . str_replace('~', '\\~', $limit) . '~u';
            $matched = preg_match($regex, $value);
            if ($matched === false) {
                throw new \LogicException("Invalid pattern constraint for $where.");
            }
            $valid = $matched === 1;
        } elseif ($rule === 'enum') {
            if (!is_array($limit)) {
                throw new \LogicException("Invalid enum constraint for $where.");
            }
            $valid = in_array($value, $limit, true);
        } else {
            if (!is_int($limit) && !is_float($limit)) {
                throw new \LogicException("Invalid $rule constraint for $where.");
            }
            $valid = match ($rule) {
                'minLength', 'maxLength' => self::stringLength($value, $rule, $limit, $where),
                'minimum', 'maximum' => self::number($value, $rule, $limit, $where),
                'minItems', 'maxItems' => self::itemCount($value, $rule, $limit, $where),
                default => throw new \LogicException("Unknown validation rule $rule for $where."),
            };
        }
        if (!$valid) {
            $description = ($rule === 'enum' ? 'allowed values ' : 'limit ') . var_export($limit, true);
            throw new \UnexpectedValueException("Validation $rule failed at $where ($description).");
        }
    }

    private static function stringLength(mixed $value, string $rule, int|float $limit, string $where): bool
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Expected string for $rule at $where.");
        }
        // OpenAPI length counts Unicode code points, not UTF-8 bytes.
        $length = preg_match_all('/./us', $value);
        if ($length === false) {
            throw new \UnexpectedValueException("Invalid UTF-8 string at $where.");
        }
        return $rule === 'minLength' ? $length >= $limit : $length <= $limit;
    }

    private static function number(mixed $value, string $rule, int|float $limit, string $where): bool
    {
        if (!is_int($value) && !is_float($value)) {
            throw new \UnexpectedValueException("Expected number for $rule at $where.");
        }
        return $rule === 'minimum' ? $value >= $limit : $value <= $limit;
    }

    private static function itemCount(mixed $value, string $rule, int|float $limit, string $where): bool
    {
        if (!is_array($value) && !$value instanceof \Countable) {
            throw new \UnexpectedValueException("Expected array/collection for $rule at $where.");
        }
        $count = count($value);
        return $rule === 'minItems' ? $count >= $limit : $count <= $limit;
    }
}
