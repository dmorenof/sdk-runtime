<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Declarative value rules (pattern, enum, length, range, item count) taken from the API contract.
 *
 * @internal Not for application code. Frozen only as the contract with sdk-generator of the same major version (see docs/PUBLIC_API.md).
 */
final class Constraints
{
    /** Rules checked by the JSON Schema validator rather than by assert(). */
    private const array JSON_SCHEMA_RULES = ['exclusiveMinimum' => 0, 'exclusiveMaximum' => 0, 'uniqueItems' => 0, 'minProperties' => 0, 'maxProperties' => 0, 'const' => 0, 'enum' => 0];

    private static ?\Opis\JsonSchema\Validator $jsonValidator = null;
    /** @var array<string,\Opis\JsonSchema\Schema> */
    private static array $jsonSchemas = [];

    /** @param array<string, mixed> $rules */
    public static function validate(mixed $value, array $rules, string $where, string $mode = 'neutral'): void
    {
        if ($rules === []) {
            return; // Most fields and every untyped-rule item land here: nothing to check.
        }
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }
        // Preserve JSON object/list identity inside declarative schema values.
        // The generator supplies JSON rather than PHP arrays for these guards.
        if (array_key_exists('jsonSchema', $rules)) {
            if (!is_string($rules['jsonSchema'])) {
                throw new \LogicException('Invalid JSON schema guard.');
            }
            try { $guard = json_decode($rules['jsonSchema'], false, 512, JSON_THROW_ON_ERROR); }
            catch (\JsonException $error) { throw new \LogicException('Malformed JSON schema guard.', 0, $error); }
            if (!is_bool($guard) && !$guard instanceof \stdClass) {
                throw new \LogicException('JSON schema guard must be an object or boolean.');
            }
            self::validate($value, ['schema' => $guard], $where, $mode);
            unset($rules['jsonSchema']);
        }
        if (is_string($value)) {
            self::assertPlainStringRules($value, $rules, $where);
        }
        $jsonRules = array_intersect_key($rules, self::JSON_SCHEMA_RULES);
        $hasSchema = array_key_exists('schema', $rules);
        if ($jsonRules !== [] || $hasSchema) {
            $raw = $hasSchema ? $rules['schema'] : (object) $jsonRules;
            if (is_array($raw)) { $raw = (object) $raw; }
            if (!is_bool($raw) && !$raw instanceof \stdClass) { throw new \LogicException('Invalid declarative schema rule.'); }
            $key = json_encode($raw, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $validator = self::validator();
            $schema = self::compileSchema($key);
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

    /**
     * `enum` and `const` on a string value need no schema: JSON equality of two strings is string equality, and
     * these are by far the most common rules, so they skip the serialize-and-validate round trip. The rule handled
     * here is removed from $rules; everything else still goes through the schema validator.
     *
     * @param array<string, mixed> $rules
     */
    private static function assertPlainStringRules(string $value, array &$rules, string $where): void
    {
        if (isset($rules['enum'], $rules['const'])) {
            return; // together they can contradict each other, which only the schema validator reports properly
        }
        if (isset($rules['enum']) && is_array($rules['enum'])) {
            if (!in_array($value, $rules['enum'], true)) {
                throw new \UnexpectedValueException("Validation enum failed at $where.");
            }
            unset($rules['enum']);
        }
        if (isset($rules['const']) && is_string($rules['const'])) {
            if ($value !== $rules['const']) {
                throw new \UnexpectedValueException("Validation const failed at $where.");
            }
            unset($rules['const']);
        }
    }

    /** Compile a self-contained schema once, using the same policy in generator and runtime. */
    public static function compileSchema(string $json): \Opis\JsonSchema\Schema
    {
        if (isset(self::$jsonSchemas[$json])) { return self::$jsonSchemas[$json]; }
        $definition = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!is_bool($definition) && !$definition instanceof \stdClass) {
            throw new \LogicException('Invalid schema definition.');
        }
        $loader = self::validator()->loader();
        return self::$jsonSchemas[$json] = is_bool($definition)
            ? $loader->loadBooleanSchema($definition, draft: '2020-12')
            : $loader->loadObjectSchema($definition, draft: '2020-12');
    }

    private static function validator(): \Opis\JsonSchema\Validator
    {
        if (self::$jsonValidator === null) {
            $parser = new \Opis\JsonSchema\Parsers\SchemaParser(options: [
                'allowDefaults' => false, 'allowFormats' => false,
                'allowFilters' => false, 'allowMappers' => false, 'allowTemplates' => false,
                'allowGlobals' => false, 'allowSlots' => false, 'allowKeywordValidators' => false,
                'allowPragmas' => false, 'allowDataKeyword' => false,
            ]);
            self::$jsonValidator = new \Opis\JsonSchema\Validator(new \Opis\JsonSchema\SchemaLoader($parser));
        }
        return self::$jsonValidator;
    }

    private static function assert(mixed $value, string $rule, mixed $limit, string $where): void
    {
        if ($rule === 'items') {
            self::assertItems($value, $limit, $where);
            return;
        } elseif ($rule === 'pattern') {
            $valid = self::matchesPattern($value, $limit, $where);
        } elseif ($rule === 'enum') {
            if (!is_array($limit)) {
                throw new \LogicException("Invalid enum constraint for $where.");
            }
            $valid = in_array($value, $limit, true);
        } elseif ($rule === 'format') {
            if (!is_string($limit) || $limit === '') {
                throw new \LogicException("Invalid format constraint for $where.");
            }
            // Formats that assert nothing for this value (unknown, or another type) never fail.
            $valid = FormatValidator::check($limit, $value) ?? true;
        } else {
            if (!is_int($limit) && !is_float($limit)) {
                throw new \LogicException("Invalid $rule constraint for $where.");
            }
            $valid = match ($rule) {
                'minLength', 'maxLength' => self::stringLength($value, $rule, $limit, $where),
                'minimum', 'maximum' => self::number($value, $rule, $limit, $where),
                'multipleOf' => self::isMultipleOf($value, $limit, $where),
                'minItems', 'maxItems' => self::itemCount($value, $rule, $limit, $where),
                default => throw new \LogicException("Unknown validation rule $rule for $where."),
            };
        }
        if (!$valid) {
            $description = ($rule === 'enum' ? 'allowed values ' : ($rule === 'format' ? 'format ' : 'limit ')) . var_export($limit, true);
            throw new \UnexpectedValueException("Validation $rule failed at $where ($description).");
        }
    }

    /** Each item of a list is hydrated as the declared scalar type and checked against the item rules. */
    private static function assertItems(mixed $value, mixed $limit, string $where): void
    {
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
    }

    private static function matchesPattern(mixed $value, mixed $limit, string $where): bool
    {
        if (!is_string($limit) || !is_string($value)) {
            throw new \UnexpectedValueException("Expected string for pattern at $where.");
        }
        $matched = preg_match('~' . str_replace('~', '\\~', $limit) . '~u', $value);
        if ($matched === false) {
            throw new \LogicException("Invalid pattern constraint for $where.");
        }
        return $matched === 1;
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

    /**
     * `multipleOf` for a number (any other value is not constrained by it). The quotient of two floats is only
     * as exact as the floats: 392.4 / 0.00001 is not 39240000, so it is compared with the rounding error a double
     * can carry at that size, which a fixed scale (the schema validator's) cannot do for large values.
     */
    private static function isMultipleOf(mixed $value, int|float $divisor, string $where): bool
    {
        if ($divisor <= 0) {
            throw new \LogicException("Invalid multipleOf constraint for $where.");
        }
        if (is_int($value) && is_int($divisor)) {
            return $value % $divisor === 0;
        }
        if (!is_int($value) && !is_float($value)) {
            return true;
        }
        $quotient = $value / $divisor;
        if (!is_finite($quotient)) {
            return false;
        }
        return abs($quotient - round($quotient)) <= max(1.0E-9, abs($quotient) * 8 * PHP_FLOAT_EPSILON);
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
