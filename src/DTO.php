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
    /** @var array<string,array<string,int|float|string|list<string|int>>> */
    protected const array VALIDATION = [];
    /** @var \WeakMap<self, array<string, mixed>>|null */
    private static ?\WeakMap $additionalPropertyValues = null;

    public function __construct(?object $json = null)
    {
        if ($json === null) {
            return;
        }

        $propertyMap = $this->constant('PROPERTY_MAP', []);
        foreach (get_object_vars($json) as $name => $value) {
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

    /** The value of a generated class constant, or $default when the model does not declare it. */
    private function constant(string $name, mixed $default): mixed
    {
        return defined(static::class . "::$name") ? constant(static::class . "::$name") : $default;
    }

    private function hydrateAdditional(mixed $value, string $where): mixed
    {
        if (!defined(static::class . '::ADDITIONAL_PROPERTIES_TYPE')) {
            throw new \InvalidArgumentException("Unknown JSON property $where.");
        }
        return Hydrator::hydrate(
            $value,
            constant(static::class . '::ADDITIONAL_PROPERTIES_TYPE'),
            constant(static::class . '::ADDITIONAL_PROPERTIES_NULLABLE'),
            $where,
        );
    }

    public function setAdditionalProperty(string $name, mixed $value): void
    {
        if (property_exists($this, $name) || array_key_exists($name, $this->constant('PROPERTY_MAP', []))) {
            throw new \InvalidArgumentException("Property " . static::class . "::$name is declared; assign it directly.");
        }
        if (!defined(static::class . '::ADDITIONAL_PROPERTIES_TYPE')) {
            throw new \InvalidArgumentException("Additional properties are not allowed by " . static::class . '.');
        }
        $where = static::class . "::$name";
        $converted = $this->hydrateAdditional($value, $where);
        Constraints::validate($converted, $this->constant('ADDITIONAL_PROPERTIES_VALIDATION', []), $where);
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
        $required = $this->constant('REQUIRED_FIELDS', []);
        if ($required === []) {
            return;
        }
        $defined = $this->fields();
        $skipped = array_merge(
            $request ? $this->constant('READ_ONLY_FIELDS', []) : [],
            $response ? $this->constant('WRITE_ONLY_FIELDS', []) : [],
        );
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
        $additionalRules = $this->constant('ADDITIONAL_PROPERTIES_VALIDATION', []);
        if ($additionalRules !== []) {
            foreach (self::additionalPropertiesFor($this) as $name => $value) {
                Constraints::validate($value, $additionalRules, static::class . "::$name");
            }
        }
        $skipped = array_merge(
            $request ? $this->constant('READ_ONLY_FIELDS', []) : [],
            $response ? $this->constant('WRITE_ONLY_FIELDS', []) : [],
        );
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
        $excluded = match ($mode) {
            'request' => $this->constant('READ_ONLY_FIELDS', []),
            default => $this->constant('WRITE_ONLY_FIELDS', []),
        };
        return Serializer::fields($this->fields(), $excluded, $this->constant('WIRE_PROPERTY_MAP', []), $mode);
    }

    public function __toString(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }
}
