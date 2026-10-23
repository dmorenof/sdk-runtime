<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Shared base for strongly typed model classes.
 * Models declare fields and REQUIRED_FIELDS; behavior is inherited once.
 * Type conversion, value rules and JSON output are delegated to Hydrator,
 * Constraints and Serializer.
 */
class DTO implements \JsonSerializable, \Stringable
{
    /** @var array<string, array<string, mixed>> Field => rules (see Constraints). */
    protected const array VALIDATION = [];
    /** @var \WeakMap<self, array<string, mixed>>|null */
    private static ?\WeakMap $additionalPropertyValues = null;
    /** @var array<class-string, array<string, mixed>> */
    private static array $declared = [];

    public function __construct(?object $json = null)
    {
        if ($json === null) {
            return;
        }

        $propertyMap = $this->declaredMap('PROPERTY_MAP');
        foreach (get_object_vars($json) as $key => $value) {
            $name = (string) $key;
            $propertyName = $propertyMap[$name] ?? $name;
            $where = static::class . "::$name";
            if (property_exists($this, $propertyName)) {
                $this->{$propertyName} = Hydrator::hydrateProperty($this, $propertyName, $value, $where);
            } else {
                self::storeAdditionalProperty($this, $name, $this->hydrateAdditional($value, $where));
            }
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

    /**
     * The array a generated class declares in constant $name, or [] when it does not.
     * Looked up once per class: constants cannot change, and models are validated on every use.
     *
     * @return array<mixed>
     */
    private function declared(string $name): array
    {
        $value = self::$declared[static::class][$name]
            ??= defined(static::class . "::$name") ? constant(static::class . "::$name") : [];
        return is_array($value) ? $value : [];
    }

    /** @return list<string> */
    private function declaredNames(string $name): array
    {
        /** @var list<string> $names */
        $names = $this->declared($name);
        return $names;
    }

    /** @return array<string, string> */
    private function declaredMap(string $name): array
    {
        /** @var array<string, string> $map */
        $map = $this->declared($name);
        return $map;
    }

    /** @return array<string, mixed> */
    private function declaredRules(string $name): array
    {
        /** @var array<string, mixed> $rules */
        $rules = $this->declared($name);
        return $rules;
    }

    /** @return list<string> Fields that do not travel in the given direction (readOnly in requests, writeOnly in responses). */
    private function directionalFields(bool $request, bool $response): array
    {
        return $request
            ? $this->declaredNames('READ_ONLY_FIELDS')
            : ($response ? $this->declaredNames('WRITE_ONLY_FIELDS') : []);
    }

    private function hydrateAdditional(mixed $value, string $where): mixed
    {
        if (!defined(static::class . '::ADDITIONAL_PROPERTIES_TYPE')) {
            throw new \InvalidArgumentException("Unknown JSON property $where.");
        }
        $type = constant(static::class . '::ADDITIONAL_PROPERTIES_TYPE');
        $nullable = constant(static::class . '::ADDITIONAL_PROPERTIES_NULLABLE');
        if (!is_string($type) || !is_bool($nullable)) {
            throw new \LogicException(static::class . ' declares malformed additional-properties metadata.');
        }
        return Hydrator::hydrate($value, $type, $nullable, $where);
    }

    public function setAdditionalProperty(string $name, mixed $value): void
    {
        if (property_exists($this, $name) || array_key_exists($name, $this->declaredMap('PROPERTY_MAP'))) {
            throw new \InvalidArgumentException("Property " . static::class . "::$name is declared; assign it directly.");
        }
        if (!defined(static::class . '::ADDITIONAL_PROPERTIES_TYPE')) {
            throw new \InvalidArgumentException("Additional properties are not allowed by " . static::class . '.');
        }
        $where = static::class . "::$name";
        $converted = $this->hydrateAdditional($value, $where);
        Constraints::validate($converted, $this->declaredRules('ADDITIONAL_PROPERTIES_VALIDATION'), $where);
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
        /** @var array<string, mixed> $declared */
        $declared = get_object_vars($this);
        return array_replace($declared, self::additionalPropertiesFor($this));
    }

    private function assertRequiredFields(bool $request = false, bool $response = false): void
    {
        $required = $this->declaredNames('REQUIRED_FIELDS');
        if ($required === []) {
            return;
        }
        $defined = $this->fields();
        $skipped = $this->directionalFields($request, $response);
        foreach ($required as $name) {
            if (!in_array($name, $skipped, true) && !array_key_exists($name, $defined)) {
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
        $additionalRules = $this->declaredRules('ADDITIONAL_PROPERTIES_VALIDATION');
        if ($additionalRules !== []) {
            foreach (self::additionalPropertiesFor($this) as $name => $value) {
                Constraints::validate($value, $additionalRules, static::class . "::$name");
            }
        }
        $skipped = $this->directionalFields($request, $response);
        foreach (static::VALIDATION as $field => $rules) {
            if (!property_exists($this, $field)) {
                throw new \LogicException("Validation refers to an unknown field " . static::class . "::$field.");
            }
            if (!array_key_exists($field, $fields) || $fields[$field] === null || in_array($field, $skipped, true)) {
                continue;
            }
            Constraints::validate($fields[$field], $rules, static::class . "::$field");
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

    /** @internal Called by Serializer after the owning model has been validated. */
    public function serializeValidated(string $mode): object
    {
        $excluded = $this->declaredNames($mode === 'request' ? 'READ_ONLY_FIELDS' : 'WRITE_ONLY_FIELDS');
        return Serializer::fields($this->fields(), $excluded, $this->declaredMap('WIRE_PROPERTY_MAP'), $mode);
    }

    public function __toString(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }
}
