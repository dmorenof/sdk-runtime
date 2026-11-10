<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Declarative value rules (pattern, enum, length, range, item count) taken from the API contract.
 */
final class Constraints
{
    private static ?\Opis\JsonSchema\Validator $jsonValidator = null;
    /** @var array<string,\Opis\JsonSchema\Schema> */
    private static array $jsonSchemas = [];

    /** @param array<string, mixed> $rules */
    public static function validate(mixed $value, array $rules, string $where, string $mode = 'neutral'): void
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }
        $jsonRules = array_intersect_key($rules, array_flip(['exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'uniqueItems', 'minProperties', 'maxProperties', 'const']));
        $hasSchema = array_key_exists('schema', $rules);
        if ($jsonRules !== [] || $hasSchema) {
            $raw = $hasSchema ? $rules['schema'] : (object) $jsonRules;
            if (is_array($raw)) { $raw = (object) $raw; }
            if (!is_bool($raw) && !$raw instanceof \stdClass) { throw new \LogicException('Invalid declarative schema rule.'); }
            $key = json_encode($raw, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $validator = self::$jsonValidator ??= new \Opis\JsonSchema\Validator();
            $definition = json_decode($key, false, 512, JSON_THROW_ON_ERROR);
            if (!is_bool($definition) && !$definition instanceof \stdClass) { throw new \LogicException('Invalid schema definition.'); }
            $schema = self::$jsonSchemas[$key] ??= is_bool($definition)
                ? $validator->loader()->loadBooleanSchema($definition, draft: '2020-12')
                : $validator->loader()->loadObjectSchema($definition, draft: '2020-12');
            try { $json = SchemaValidator::snapshot(Serializer::value($value, $mode)); }
            catch (\JsonException $error) { throw new \UnexpectedValueException("Invalid JSON value at $where.", 0, $error); }
            $result = $validator->validate($json, $schema);
            if ($hasSchema && $jsonRules !== []) { self::validate($value, $jsonRules, $where, $mode); }
            if (!$result->isValid()) {
                throw new \UnexpectedValueException('Validation ' . ($result->error()?->keyword() ?? 'schema') . " failed at $where.");
            }
        }
        foreach (array_diff_key($rules, $jsonRules, ['schema' => true]) as $rule => $limit) {
            self::assert($value, $rule, $limit, $where);
        }
    }

    private static function assert(mixed $value, string $rule, mixed $limit, string $where): void
    {
        if ($rule === 'items') {
            if (!is_array($value) || !array_is_list($value)) {
                throw new \UnexpectedValueException("Expected a list for items at $where.");
            }
            if (!is_array($limit) || !in_array($limit['type'] ?? null, ['string', 'int', 'float', 'bool', 'binary'], true)
                || !is_array($limit['rules'] ?? [])) {
                throw new \LogicException("Invalid items constraint for $where.");
            }
            $itemRules = [];
            foreach ($limit['rules'] ?? [] as $ruleName => $ruleValue) {
                if (!is_string($ruleName)) {
                    throw new \LogicException("Item rule names must be strings at $where.");
                }
                $itemRules[$ruleName] = $ruleValue;
            }
            foreach ($value as $index => $item) {
                $at = $where . '[' . $index . ']';
                Hydrator::hydrate($item, $limit['type'], false, $at);
                self::validate($item, $itemRules, $at);
            }
            return;
        } elseif ($rule === 'pattern') {
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
