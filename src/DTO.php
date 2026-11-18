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
    /** @var array<string,mixed> */
    protected const array MODEL_VALIDATION = [];
    /** @var \WeakMap<self, array<string, mixed>>|null */
    /** @var array<class-string, array<string, mixed>> */
    private static array $declared = [];

    /** @var array<class-string, bool> Whether the class is a subtype that must agree with its discriminator. */
    private static array $discriminated = [];

    /** While above zero, constructing a model from JSON skips its own validation: the caller validates the whole tree once. */
    private static int $deferred = 0;

    /** While above zero, a JSON property the model does not declare is ignored instead of rejected. */
    private static int $tolerant = 0;

    public function __construct(?object $json = null)
    {
        if ($json === null) {
            return;
        }

        if (array_key_exists('jsonSchema', static::MODEL_VALIDATION)) {
            Constraints::validate(SchemaValidator::snapshot($json), ['jsonSchema' => static::MODEL_VALIDATION['jsonSchema']], static::class);
        }
        $propertyMap = $this->declaredMap('PROPERTY_MAP');
        foreach (get_object_vars($json) as $key => $value) {
            $name = (string) $key;
            $propertyName = $propertyMap[$name] ?? $name;
            $where = static::class . "::$name";
            if (property_exists($this, $propertyName)) {
                $this->{$propertyName} = Hydrator::hydrateProperty($this, $propertyName, $value, $where);
            } elseif (self::$tolerant === 0 || defined(static::class . '::ADDITIONAL_PROPERTIES_TYPE')) {
                AdditionalPropertyStore::put($this, $name, $this->hydrateAdditional($value, $where));
            }
        }

        // Schema-required fields are checked both when hydrating incoming JSON
        // and again before encoding a manually created outbound DTO.
        // Legacy Request/Response subclasses can still override validate().
        // A neutral DTO does not infer direction from the PHP class name.
        if (self::$deferred === 0) {
            $this->validate();
        }
    }

    /**
     * Whether a model or collection being built from an answer leaves its validation to the caller (see readingResponse()).
     *
     * @internal Used by the runtime itself; not covered by the backward-compatibility promise.
     */
    public static function isDeferred(): bool
    {
        return self::$deferred > 0;
    }

    /**
     * Runs $work reading an API's answer: validation is deferred (the caller validates the whole tree once, in the
     * direction it knows, instead of once per level while it is built) and a property the model does not declare
     * is ignored, so an API that adds a field does not break clients generated before it.
     *
     * @internal Used by the runtime itself; not covered by the backward-compatibility promise.
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public static function readingResponse(\Closure $work): mixed
    {
        self::$tolerant++;
        try {
            return self::deferringValidation($work);
        } finally {
            self::$tolerant--;
        }
    }

    /**
     * Runs $work with model validation deferred (see readingResponse()).
     *
     * @internal Used by the runtime itself; not covered by the backward-compatibility promise.
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public static function deferringValidation(\Closure $work): mixed
    {
        self::$deferred++;
        try {
            return $work();
        } finally {
            self::$deferred--;
        }
    }

    /**
     * Runs $work with validation on, whatever the caller deferred: choosing between alternative schemas needs a
     * model that does not fit to fail as it is built.
     *
     * @internal Used by the runtime itself; not covered by the backward-compatibility promise.
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public static function validating(\Closure $work): mixed
    {
        $saved = self::$deferred;
        self::$deferred = 0;
        try {
            return $work();
        } finally {
            self::$deferred = $saved;
        }
    }

    /** Construct through the shared hydrator so a base model can dispatch to a subtype. */
    public static function fromValue(object $json): static
    {
        $result = Hydrator::hydrate($json, static::class, false, static::class);
        if (!$result instanceof static) {
            throw new \LogicException('Hydrator returned an incompatible DTO.');
        }
        return $result;
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
        AdditionalPropertyStore::put($this, $name, $converted);
    }

    public function getAdditionalProperty(string $name): mixed
    {
        return AdditionalPropertyStore::all($this)[$name] ?? null;
    }

    /** @return array<string, mixed> */
    public function additionalProperties(): array
    {
        return AdditionalPropertyStore::all($this);
    }

    /** @return array<string, mixed> */
    private function fields(): array
    {
        /** @var array<string, mixed> $declared */
        $declared = get_object_vars($this);
        $additional = AdditionalPropertyStore::all($this);
        return $additional === [] ? $declared : array_replace($declared, $additional);
    }

    /** @param array<string, mixed> $defined The model's fields, declared and additional. */
    private function assertRequiredFields(array $defined, bool $request = false, bool $response = false): void
    {
        $required = $this->declaredNames('REQUIRED_FIELDS');
        if ($required === []) {
            return;
        }
        // Neutral construction verifies common requirements immediately.
        // A field marked readOnly or writeOnly is required only when the
        // surrounding operation supplies its direction (response or request).
        $skipped = !$request && !$response
            ? array_merge($this->declaredNames('READ_ONLY_FIELDS'), $this->declaredNames('WRITE_ONLY_FIELDS'))
            : $this->directionalFields($request, $response);
        foreach ($required as $name) {
            if ($request && array_key_exists($name, $defined) && $defined[$name] === null) {
                foreach ($this->declaredRules('OPERATION_FIELDS') as $names) {
                    if (is_array($names) && in_array($name, $names, true)) {
                        throw new \UnexpectedValueException("Required wire parameter " . static::class . "::$name cannot be omitted as null.");
                    }
                }
            }
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
        $fields = $this->fields();
        $this->assertRequiredFields($fields, $request, $response);
        $mode = $request ? 'request' : ($response ? 'response' : 'neutral');
        if (static::MODEL_VALIDATION !== []) {
            Constraints::validate($this->serializeValidated($mode), static::MODEL_VALIDATION, static::class, $mode);
        }
        if (self::$discriminated[static::class] ??= defined(static::class . '::DISCRIMINATOR_BASE') && constant(static::class . '::DISCRIMINATOR_BASE') !== static::class) {
            /** @var string $property */
            $property = constant(static::class . '::DISCRIMINATOR_PROPERTY');
            /** @var array<string, class-string> $mapping */
            $mapping = constant(static::class . '::DISCRIMINATOR_MAPPING');
            $json = Serializer::fields($fields, [], $this->declaredMap('WIRE_PROPERTY_MAP'), 'neutral');
            $selected = Discriminator::select($json, $property, $mapping, static::class);
            if (!$this instanceof $selected) {
                throw new \UnexpectedValueException('Discriminator disagrees with the hydrated class at ' . static::class . '.');
            }
        }
        $additionalRules = $this->declaredRules('ADDITIONAL_PROPERTIES_VALIDATION');
        if ($additionalRules !== []) {
            foreach (AdditionalPropertyStore::all($this) as $name => $value) {
                Constraints::validate($value, $additionalRules, static::class . "::$name", $mode);
            }
        }
        $skipped = static::VALIDATION === [] ? [] : $this->directionalFields($request, $response);
        foreach (static::VALIDATION as $field => $rules) {
            if (!property_exists($this, $field)) {
                throw new \LogicException("Validation refers to an unknown field " . static::class . "::$field.");
            }
            if (!array_key_exists($field, $fields) || ($fields[$field] === null && !$this->rulesApplyToNull($field, $rules)) || in_array($field, $skipped, true)) {
                continue;
            }
            Constraints::validate($fields[$field], $rules, static::class . "::$field", $mode);
        }
        foreach ($fields as $value) {
            if (is_object($value) || is_array($value)) {
                self::validateNestedValue($value, $mode);
            }
        }
    }

    /** @param array<string,mixed> $rules */
    private function rulesApplyToNull(string $field, array $rules): bool
    {
        if (!array_key_exists('const', $rules) && !array_key_exists('enum', $rules) && !array_key_exists('schema', $rules) && !array_key_exists('jsonSchema', $rules)) { return false; }
        foreach ($this->declaredRules('OPERATION_FIELDS') as $names) {
            if (is_array($names) && in_array($field, $names, true)) { return false; }
        }
        return true;
    }

    /**
     * Shared traversal for DTO fields, collection items, map values and unions.
     *
     * @internal Used by the runtime itself; not covered by the backward-compatibility promise.
     */
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
            if ($value instanceof TypedArray) { $value->validateForMode($mode); }
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

    /** @internal Multipart fields preserve direct PSR uploads, which are not JSON values. */
    public function multipartSerializeForRequest(): object
    {
        $this->validateForRequest();
        return Serializer::fields($this->fields(), $this->declaredNames('READ_ONLY_FIELDS'), $this->declaredMap('WIRE_PROPERTY_MAP'), 'request', true);
    }

    public function __toString(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }
}
