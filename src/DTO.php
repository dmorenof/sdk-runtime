<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Shared base for strongly typed model classes.
 * Models declare fields and REQUIRED_FIELDS; behavior is inherited once.
 */
class DTO implements \JsonSerializable, \Stringable
{
    /** @var array<string,array<string,int|float|string|list<string|int>>> */
    protected const array VALIDATION = [];
    /** @var \WeakMap<self, array<string, mixed>>|null */
    private static ?\WeakMap $additionalPropertyValues = null;

    /** @var array<class-string, array<string, \ReflectionNamedType>> */
    private static array $propertyTypes = [];

    public function __construct(?object $json = null)
    {
        if ($json === null) {
            return;
        }

        $fields = get_object_vars($json);
        $propertyMap = defined(static::class . '::PROPERTY_MAP')
            ? constant(static::class . '::PROPERTY_MAP')
            : [];
        foreach ($fields as $name => $value) {
            $propertyName = $propertyMap[$name] ?? $name;
            if (!property_exists($this, $propertyName)) {
                if (!defined(static::class . '::ADDITIONAL_PROPERTIES_TYPE')) {
                    throw new \InvalidArgumentException("Unknown JSON property " . static::class . "::$name.");
                }
                $type = constant(static::class . '::ADDITIONAL_PROPERTIES_TYPE');
                $nullable = constant(static::class . '::ADDITIONAL_PROPERTIES_NULLABLE');
                $converted = self::hydrateValue($value, $type, $nullable, static::class . "::$name");
                self::storeAdditionalProperty($this, $name, $converted);
                continue;
            }
            $type = self::$propertyTypes[static::class][$propertyName] ?? null;
            if ($type === null) {
                $property = new \ReflectionProperty($this, $propertyName);
                if (!$property->isPublic() || $property->isStatic()) {
                    throw new \InvalidArgumentException("JSON property " . static::class . "::$propertyName is not writable.");
                }
                $type = $property->getType();
                if (!$type instanceof \ReflectionNamedType) {
                    throw new \InvalidArgumentException("Unsupported PHP property type at " . static::class . "::$propertyName.");
                }
                self::$propertyTypes[static::class][$propertyName] = $type;
            }
            $this->{$propertyName} = self::convert($value, $type, static::class . "::$name");
        }

        // Schema-required fields are checked both when hydrating incoming JSON
        // and again before encoding a manually created outbound DTO.
        if ($this instanceof Request) {
            $this->validateForRequest();
        } elseif ($this instanceof Response) {
            $this->validateForResponse();
        } else {
            $this->validate();
        }
    }

    private static function convert(mixed $value, \ReflectionNamedType $type, string $where): mixed
    {
        return self::convertByType(
            $value,
            $type->getName(),
            $type->allowsNull(),
            $type->isBuiltin(),
            $where,
        );
    }

    public static function hydrateValue(mixed $value, string $kind, bool $nullable, string $where): mixed
    {
        $kind = match ($kind) {
            'integer' => 'int', 'double' => 'float', 'boolean' => 'bool',
            default => ltrim($kind, '\\'),
        };
        $builtin = in_array($kind, ['mixed', 'int', 'float', 'string', 'bool', 'null'], true);
        return self::convertByType($value, $kind, $nullable, $builtin, $where);
    }

    private static function convertByType(
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

        if (is_subclass_of($kind, Map::class)) {
            if ($value instanceof $kind) {
                return $value;
            }
            if (!is_object($value) && !is_array($value)) {
                throw new \UnexpectedValueException("Expected JSON object at $where.");
            }
            return new $kind($value);
        }

        if (enum_exists($kind)) {
            if ($value instanceof $kind) {
                return $value;
            }
            if (!is_string($value) && !is_int($value)) {
                throw new \UnexpectedValueException("Invalid enum value at $where.");
            }
            return $kind::from($value);
        }

        if (is_subclass_of($kind, TypedArray::class)) {
            if ($value instanceof $kind) {
                return $value;
            }
            // Compatible with Casafari's empty-object-as-empty-list behavior.
            if (is_object($value) && get_object_vars($value) === []) {
                $value = [];
            }
            if (!is_array($value) || !array_is_list($value)) {
                throw new \UnexpectedValueException("Expected a JSON array at $where.");
            }
            $result = new $kind();
            $expectedType = $result->getExpectedType();
            foreach ($value as $index => $item) {
                $itemWhere = $where . "[$index]";
                $item = self::hydrateValue($item, $expectedType, false, $itemWhere);
                $result->append($item);
            }
            return $result;
        }

        if (is_subclass_of($kind, self::class)) {
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

    public static function validateConstraints(mixed $value, array $rules, string $where): void
    {
        foreach ($rules as $name => $limit) {
            self::assertConstraint('$value', $value, $name, $limit, $where);
        }
    }

    public function setAdditionalProperty(string $name, mixed $value): void
    {
        $propertyMap = defined(static::class . '::PROPERTY_MAP')
            ? constant(static::class . '::PROPERTY_MAP')
            : [];
        if (property_exists($this, $name) || array_key_exists($name, $propertyMap)) {
            throw new \InvalidArgumentException("Property " . static::class . "::$name is declared; assign it directly.");
        }
        if (!defined(static::class . '::ADDITIONAL_PROPERTIES_TYPE')) {
            throw new \InvalidArgumentException("Additional properties are not allowed by " . static::class . '.');
        }
        $type = constant(static::class . '::ADDITIONAL_PROPERTIES_TYPE');
        $nullable = constant(static::class . '::ADDITIONAL_PROPERTIES_NULLABLE');
        $converted = self::hydrateValue($value, $type, $nullable, static::class . "::$name");
        $rules = defined(static::class . '::ADDITIONAL_PROPERTIES_VALIDATION')
            ? constant(static::class . '::ADDITIONAL_PROPERTIES_VALIDATION')
            : [];
        self::validateConstraints($converted, $rules, static::class . "::$name");
        self::storeAdditionalProperty($this, $name, $converted);
    }

    public function getAdditionalProperty(string $name): mixed
    {
        return self::additionalPropertiesFor($this)[$name] ?? null;
    }

    /** @return array<string, mixed> */
    public function additionalProperties(): array
    {
        return self::additionalPropertiesFor($this);
    }

    private static function storeAdditionalProperty(self $dto, string $name, mixed $value): void
    {
        self::$additionalPropertyValues ??= new \WeakMap();
        $values = self::$additionalPropertyValues[$dto] ?? [];
        $values[$name] = $value;
        self::$additionalPropertyValues[$dto] = $values;
    }

    /** @return array<string, mixed> */
    private static function additionalPropertiesFor(self $dto): array
    {
        return self::$additionalPropertyValues[$dto] ?? [];
    }

    /** @return array<string, mixed> */
    private function fields(): array
    {
        return array_replace(get_object_vars($this), self::additionalPropertiesFor($this));
    }

    private function assertRequiredFields(bool $request = false, bool $response = false): void
    {
        if (!defined(static::class . '::REQUIRED_FIELDS')) {
            return;
        }
        $defined = $this->fields();
        $readOnly = $request && defined(static::class . '::READ_ONLY_FIELDS')
            ? constant(static::class . '::READ_ONLY_FIELDS')
            : [];
        $writeOnly = $response && defined(static::class . '::WRITE_ONLY_FIELDS')
            ? constant(static::class . '::WRITE_ONLY_FIELDS')
            : [];
        foreach (constant(static::class . '::REQUIRED_FIELDS') as $name) {
            if (in_array($name, $readOnly, true) || in_array($name, $writeOnly, true)) {
                continue;
            }
            if (!array_key_exists($name, $defined)) {
                throw new \UnexpectedValueException("Required JSON property " . static::class . "::$name is absent.");
            }
        }
    }

    /**
     * Run optional, declarative constraints and validate nested DTOs.
     * Undefined optional fields are skipped; a nullable field can be null.
     * Generated DTO classes declare rules, never duplicate implementations.
     */
    public function validate(): void
    {
        $this->validateWithMode();
    }

    public function validateForRequest(): void
    {
        $this->validateWithMode(true, false);
    }

    public function validateForResponse(): void
    {
        $this->validateWithMode(false, true);
    }

    private function validateWithMode(bool $request = false, bool $response = false): void
    {
        $this->assertRequiredFields($request, $response);
        $fields = $this->fields();
        $additionalRules = defined(static::class . '::ADDITIONAL_PROPERTIES_VALIDATION')
            ? constant(static::class . '::ADDITIONAL_PROPERTIES_VALIDATION')
            : [];
        if ($additionalRules !== []) {
            foreach (self::additionalPropertiesFor($this) as $name => $value) {
                self::validateConstraints($value, $additionalRules, static::class . "::$name");
            }
        }
        foreach (static::VALIDATION as $field => $rules) {
            if (!property_exists($this, $field)) {
                throw new \LogicException("Validation refers to an unknown field " . static::class . "::$field.");
            }
            if (!array_key_exists($field, $fields) || $fields[$field] === null) {
                continue;
            }
            if ($request && defined(static::class . '::READ_ONLY_FIELDS')
                && in_array($field, constant(static::class . '::READ_ONLY_FIELDS'), true)) {
                continue;
            }
            if ($response && defined(static::class . '::WRITE_ONLY_FIELDS')
                && in_array($field, constant(static::class . '::WRITE_ONLY_FIELDS'), true)) {
                continue;
            }
            foreach ($rules as $name => $limit) {
                self::assertConstraint($field, $fields[$field], $name, $limit);
            }
        }
        $mode = $request ? 'request' : ($response ? 'response' : 'neutral');
        foreach ($fields as $value) {
            if (is_object($value) || is_array($value)) {
                self::validateNestedValue($value, $mode);
            }
        }
    }

    /** Shared traversal for DTO fields, collection items, map values and unions. */
    public static function validateNestedValue(mixed $value, string $mode): void
    {
        if ($value instanceof self || $value instanceof Map) {
            match ($mode) {
                'request' => $value->validateForRequest(),
                'response' => $value->validateForResponse(),
                default => $value->validate(),
            };
        } elseif ($value instanceof AnyOf) {
            $value->validateForMode($mode);
        } elseif ($value instanceof TypedArray || is_array($value)) {
            foreach ($value as $item) {
                if (is_object($item) || is_array($item)) {
                    self::validateNestedValue($item, $mode);
                }
            }
        }
    }
    private static function assertConstraint(
        string $field,
        mixed $value,
        string $rule,
        mixed $limit,
        ?string $location = null,
    ): void
    {
        $where = $location ?? static::class . "::$field";
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
                'minLength', 'maxLength' => self::validateStringLength($value, $rule, $limit, $where),
                'minimum', 'maximum' => self::validateNumber($value, $rule, $limit, $where),
                'minItems', 'maxItems' => self::validateItemCount($value, $rule, $limit, $where),
                default => throw new \LogicException("Unknown validation rule $rule for $where."),
            };
        }
        if (!$valid) {
            $description = $rule === 'enum' ? 'allowed values ' . var_export($limit, true) : "limit $limit";
            throw new \UnexpectedValueException("Validation $rule failed at $where ($description).");
        }
    }

    private static function validateStringLength(mixed $value, string $rule, int|float $limit, string $where): bool
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

    private static function validateNumber(mixed $value, string $rule, int|float $limit, string $where): bool
    {
        if (!is_int($value) && !is_float($value)) {
            throw new \UnexpectedValueException("Expected number for $rule at $where.");
        }
        return $rule === 'minimum' ? $value >= $limit : $value <= $limit;
    }

    private static function validateItemCount(mixed $value, string $rule, int|float $limit, string $where): bool
    {
        if (!is_array($value) && !$value instanceof \Countable) {
            throw new \UnexpectedValueException("Expected array/collection for $rule at $where.");
        }
        $count = count($value);
        return $rule === 'minItems' ? $count >= $limit : $count <= $limit;
    }

    public function jsonSerialize(): object
    {
        $this->validate();
        return $this->serializeValidated('neutral');
    }

    public function jsonSerializeForRequest(): object
    {
        $this->validateForRequest();
        return $this->serializeValidated('request');
    }

    public function jsonSerializeForResponse(): object
    {
        $this->validateForResponse();
        return $this->serializeValidated('response');
    }

    private function serializeValidated(string $mode): object
    {
        $excluded = $mode === 'request'
            ? (defined(static::class . '::READ_ONLY_FIELDS') ? constant(static::class . '::READ_ONLY_FIELDS') : [])
            : (defined(static::class . '::WRITE_ONLY_FIELDS') ? constant(static::class . '::WRITE_ONLY_FIELDS') : []);
        return self::filterFields($this->fields(), $excluded, $mode);
    }
    /** @param array<string, mixed> $fields @param list<string> $excluded */
    private static function filterFields(array $fields, array $excluded, string $mode): object
    {
        $serialized = [];
        $wireNames = defined(static::class . '::WIRE_PROPERTY_MAP')
            ? constant(static::class . '::WIRE_PROPERTY_MAP')
            : [];
        foreach ($fields as $name => $value) {
            if (!in_array($name, $excluded, true)) {
                $wireName = $wireNames[$name] ?? $name;
                $serialized[$wireName] = self::serializeValueForMode($value, $mode);
            }
        }
        return (object) $serialized;
    }

    public static function serializeValueForMode(mixed $value, string $mode): mixed
    {
        if ($value instanceof self) {
            if ($mode === 'request') {
                return $value->serializeValidated('request');
            }
            if ($mode === 'response') {
                return $value->serializeValidated('response');
            }
            return $value->serializeValidated($value instanceof Request ? 'request' : ($value instanceof Response ? 'response' : 'neutral'));
        }
        if ($value instanceof TypedArray) {
            $items = [];
            foreach ($value as $key => $item) {
                $items[$key] = self::serializeValueForMode($item, $mode);
            }
            return $items;
        }
        if ($value instanceof Map || $value instanceof AnyOf) {
            return $value->serializeValidatedForMode($mode);
        }
        if (is_array($value)) {
            return array_map(
                static fn (mixed $item): mixed => self::serializeValueForMode($item, $mode),
                $value,
            );
        }
        return $value;
    }

    public function __toString(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }
}