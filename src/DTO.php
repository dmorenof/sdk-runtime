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
    /** @var list<string> Field names that must be present. A model that requires none declares nothing. */
    protected const array REQUIRED_FIELDS = [];
    /** @var array<string, array<string, mixed>> Field => rules (see Constraints). */
    protected const array VALIDATION = [];
    /** @var array<string,mixed> */
    protected const array MODEL_VALIDATION = [];
    /** @var array<string, string> JSON name => PHP property name, for names that are not valid or unique PHP identifiers. */
    protected const array PROPERTY_MAP = [];
    /** @var array<string, string> PHP property name => JSON name. */
    protected const array WIRE_PROPERTY_MAP = [];
    /** @var list<string> Fields that are sent in no request. */
    protected const array READ_ONLY_FIELDS = [];
    /** @var list<string> Fields that are present in no response. */
    protected const array WRITE_ONLY_FIELDS = [];
    /** @var array<string, array<string, string>> Where each parameter of an operation input travels: location => wire name => property. */
    protected const array OPERATION_FIELDS = [];
    /** @var array<string, mixed> Rules for the values of undeclared (additional) properties. */
    protected const array ADDITIONAL_PROPERTIES_VALIDATION = [];

    /** @var array<class-string, bool> Whether the class is a subtype that must agree with its discriminator. */
    private static array $discriminated = [];

    /** @var array<class-string, list<string>> Properties whose `set` hook checks their rules (see assigning()). */
    private static array $hooked = [];

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
        foreach (get_object_vars($json) as $key => $value) {
            $this->assignMember((string) $key, $value);
        }

        // The model checks itself, one level: what it requires, its own rules and the rules of the model as a whole.
        // Models and collections inside it were checked as they were built, so nothing is walked twice.
        $this->validateLevel('neutral');
    }

    /**
     * Sets one member of a JSON object on the model: the property it maps to, or an additional property.
     * A generated model overrides this with a `match` that converts the common cases inline, and hands every other
     * member to `parent::assignMember()`, which handles whatever the model declares.
     *
     * @internal Used by generated models; not covered by the backward-compatibility promise.
     */
    protected function assignMember(string $name, mixed $value): void
    {
        $propertyName = static::PROPERTY_MAP[$name] ?? $name;
        $where = static::class . "::$name";
        if (property_exists($this, $propertyName)) {
            $this->{$propertyName} = Hydrator::hydrateProperty($this, $propertyName, $value, $where);
        } elseif (self::$tolerant === 0 || defined(static::class . '::ADDITIONAL_PROPERTIES_TYPE')) {
            AdditionalPropertyStore::put($this, $name, $this->hydrateAdditional($value, $where));
        }
    }

    /**
     * Runs $work reading an API's answer: a property the model does not declare is ignored, so an API that adds a
     * field does not break clients generated before it.
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
            return $work();
        } finally {
            self::$tolerant--;
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

    /** @return list<string> Fields that do not travel in the given direction (readOnly in requests, writeOnly in responses). */
    private function directionalFields(bool $request, bool $response): array
    {
        return $request
            ? static::READ_ONLY_FIELDS
            : ($response ? static::WRITE_ONLY_FIELDS : []);
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
        if (property_exists($this, $name) || array_key_exists($name, static::PROPERTY_MAP)) {
            throw new \InvalidArgumentException("Property " . static::class . "::$name is declared; assign it directly.");
        }
        if (!defined(static::class . '::ADDITIONAL_PROPERTIES_TYPE')) {
            throw new \InvalidArgumentException("Additional properties are not allowed by " . static::class . '.');
        }
        $where = static::class . "::$name";
        $converted = $this->hydrateAdditional($value, $where);
        Constraints::validate($converted, static::ADDITIONAL_PROPERTIES_VALIDATION, $where);
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

    /**
     * The properties that hold a value. (`get_object_vars()` is not used: with a `set` hook, PHP reports an
     * uninitialized property as null, and a field that was never set must stay absent.)
     *
     * @internal Used by the runtime itself; not covered by the backward-compatibility promise.
     * @return array<string, mixed>
     */
    public function initializedProperties(): array
    {
        $values = [];
        // An array cast holds initialized properties only; the names of non-public ones start with a NUL byte.
        foreach ((array) $this as $name => $value) {
            if (is_string($name) && $name !== '' && $name[0] !== "\0") {
                $values[$name] = $value;
            }
        }
        return $values;
    }

    /** @return array<string, mixed> */
    private function fields(): array
    {
        $declared = $this->initializedProperties();
        $additional = AdditionalPropertyStore::all($this);
        return $additional === [] ? $declared : array_replace($declared, $additional);
    }

    /** @param array<string, mixed> $defined The model's fields, declared and additional. */
    private function assertRequiredFields(array $defined, bool $request = false, bool $response = false): void
    {
        $required = static::REQUIRED_FIELDS;
        if ($required === []) {
            return;
        }
        // Neutral construction verifies common requirements immediately.
        // A field marked readOnly or writeOnly is required only when the
        // surrounding operation supplies its direction (response or request).
        $skipped = !$request && !$response
            ? array_merge(static::READ_ONLY_FIELDS, static::WRITE_ONLY_FIELDS)
            : $this->directionalFields($request, $response);
        foreach ($required as $name) {
            if ($request && array_key_exists($name, $defined) && $defined[$name] === null) {
                foreach (static::OPERATION_FIELDS as $names) {
                    if (in_array($name, $names, true)) {
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
     * Checks the whole model on demand: what it requires, every rule, and every model, collection and map inside it.
     * Nothing calls this by itself: a model checks its own level when it is built, and each rule when a value
     * is assigned (see assigning()), so this is for a check you want to make explicitly.
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

    /**
     * Checks this model and nothing below it: the one check an operation input gets when a request is sent.
     *
     * @internal Used by the runtime itself; not covered by the backward-compatibility promise.
     */
    public function validateLevel(string $mode): void
    {
        $fields = $this->fields();
        $this->assertRequiredFields($fields, $mode === 'request', $mode === 'response');
        $this->checkModelRules($fields, $mode);
        $this->checkFieldRules($fields, $mode, true);
    }

    private function validateWithMode(bool $request = false, bool $response = false): void
    {
        $mode = $request ? 'request' : ($response ? 'response' : 'neutral');
        $fields = $this->fields();
        $this->assertRequiredFields($fields, $request, $response);
        $this->checkModelRules($fields, $mode);
        $this->checkFieldRules($fields, $mode, false);
        foreach ($fields as $value) {
            if ((is_object($value) && !$value instanceof \UnitEnum) || is_array($value)) {
                self::validateNestedValue($value, $mode);
            }
        }
    }

    /** @param array<string, mixed> $fields */
    private function checkModelRules(array $fields, string $mode): void
    {
        if (static::MODEL_VALIDATION !== []) {
            Constraints::validate($this->serializeValidated($mode), static::MODEL_VALIDATION, static::class, $mode);
        }
        if (self::$discriminated[static::class] ??= defined(static::class . '::DISCRIMINATOR_BASE') && constant(static::class . '::DISCRIMINATOR_BASE') !== static::class) {
            /** @var string $property */
            $property = constant(static::class . '::DISCRIMINATOR_PROPERTY');
            /** @var array<string, class-string> $mapping */
            $mapping = constant(static::class . '::DISCRIMINATOR_MAPPING');
            $json = Serializer::fields($fields, [], static::WIRE_PROPERTY_MAP, 'neutral');
            $selected = Discriminator::select($json, $property, $mapping, static::class);
            if (!$this instanceof $selected) {
                throw new \UnexpectedValueException('Discriminator disagrees with the hydrated class at ' . static::class . '.');
            }
        }
        if (static::ADDITIONAL_PROPERTIES_VALIDATION !== []) {
            foreach (AdditionalPropertyStore::all($this) as $name => $value) {
                Constraints::validate($value, static::ADDITIONAL_PROPERTIES_VALIDATION, static::class . "::$name", $mode);
            }
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @param bool $skipHooked Leave out the properties whose `set` hook already checked their value.
     */
    private function checkFieldRules(array $fields, string $mode, bool $skipHooked): void
    {
        $skipped = static::VALIDATION === [] ? [] : $this->directionalFields($mode === 'request', $mode === 'response');
        if ($skipHooked && static::VALIDATION !== []) {
            $skipped = array_merge($skipped, self::hookedFields());
        }
        foreach (static::VALIDATION as $field => $rules) {
            if (!property_exists($this, $field)) {
                throw new \LogicException("Validation refers to an unknown field " . static::class . "::$field.");
            }
            if (!array_key_exists($field, $fields) || ($fields[$field] === null && !$this->rulesApplyToNull($field, $rules)) || in_array($field, $skipped, true)) {
                continue;
            }
            Constraints::validate($fields[$field], $rules, static::class . "::$field", $mode);
        }
    }

    /**
     * The properties of this class that have rules and a `set` hook, worked out once per class.
     *
     * @return list<string>
     */
    private static function hookedFields(): array
    {
        if (isset(self::$hooked[static::class])) {
            return self::$hooked[static::class];
        }
        $names = [];
        if ((new \ReflectionClass(\ReflectionProperty::class))->hasMethod('hasHook')) { // PHP 8.4
            foreach (array_keys(static::VALIDATION) as $field) {
                if (property_exists(static::class, $field) && (new \ReflectionProperty(static::class, $field))->hasHook(\PropertyHookType::Set)) {
                    $names[] = $field;
                }
            }
        }
        return self::$hooked[static::class] = $names;
    }

    /**
     * Checks one value against the rules of its property. The `set` hook of a property that has rules calls it
     * (generated models, PHP 8.4), so a value is checked once, when it is assigned or hydrated.
     *
     * @internal Used by generated models; not covered by the backward-compatibility promise.
     */
    final protected function assigning(string $field, mixed $value): void
    {
        $rules = static::VALIDATION[$field] ?? null;
        if ($rules === null || ($value === null && !$this->rulesApplyToNull($field, $rules))) {
            return;
        }
        Constraints::validate($value, $rules, static::class . "::$field", 'neutral');
    }

    /** @param array<string,mixed> $rules */
    private function rulesApplyToNull(string $field, array $rules): bool
    {
        if (!array_key_exists('const', $rules) && !array_key_exists('enum', $rules) && !array_key_exists('schema', $rules) && !array_key_exists('jsonSchema', $rules)) { return false; }
        foreach (static::OPERATION_FIELDS as $names) {
            if (in_array($field, $names, true)) { return false; }
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
        return $this->serializeValidated('neutral');
    }

    public function jsonSerializeForRequest(): object
    {
        return $this->serializeValidated('request');
    }

    public function jsonSerializeForResponse(): object
    {
        return $this->serializeValidated('response');
    }

    /** @internal Called by Serializer after the owning model has been validated. */
    public function serializeValidated(string $mode): object
    {
        $excluded = $mode === 'request' ? static::READ_ONLY_FIELDS : static::WRITE_ONLY_FIELDS;
        return Serializer::fields($this->fields(), $excluded, static::WIRE_PROPERTY_MAP, $mode);
    }

    /** @internal Multipart fields preserve direct PSR uploads, which are not JSON values. */
    public function multipartSerializeForRequest(): object
    {
        return Serializer::fields($this->fields(), static::READ_ONLY_FIELDS, static::WIRE_PROPERTY_MAP, 'request', true);
    }

    public function __toString(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }
}
