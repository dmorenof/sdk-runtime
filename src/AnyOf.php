<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Shared runtime for OpenAPI anyOf/oneOf generated wrappers.
 * Generated subclasses provide only their permitted PHP types.
 */
class AnyOf implements \JsonSerializable
{
    /** @var list<string> Class names, enum names or the scalar kinds 'string', 'int', 'float', 'bool', 'null'. */
    protected const array TYPES = [];

    /** @var list<array<string, mixed>> Original branch descriptors, independent of PHP types. */
    protected const array BRANCH_SCHEMAS = [];
    /** @var array<string, array<string, mixed>> */
    protected const array SCHEMA_DEFINITIONS = [];
    protected const string DISCRIMINATOR_PROPERTY = '';
    /** @var array<string, class-string> */
    protected const array DISCRIMINATOR_MAPPING = [];

    /** @param list<mixed> $matches */
    final protected function __construct(
        private readonly mixed $original,
        private readonly array $matches,
    ) {}

    public static function fromValue(mixed $value): static
    {
        $original = SchemaValidator::snapshot($value);
        if (static::BRANCH_SCHEMAS !== []) {
            return self::fromSchemaValue($original);
        }
        $matches = [];
        $failures = [];
        foreach (static::TYPES as $type) {
            try {
                $matches[] = Hydrator::hydrate($value, $type, false, static::class);
            } catch (\UnexpectedValueException|\InvalidArgumentException|\ValueError|\TypeError $exception) {
                $failures[] = $type . ': ' . $exception->getMessage();
            }
        }

        if ($matches === []) {
            try {
                static::assertMatchCount(0);
            } catch (\UnexpectedValueException $exception) {
                throw new \UnexpectedValueException(
                    $exception->getMessage() . ' ' . implode(' | ', $failures),
                    0,
                    $exception,
                );
            }
        }
        static::assertMatchCount(count($matches));
        return new static($original, $matches);
    }

    private static function fromSchemaValue(mixed $original): static
    {
        $indexes = self::matchingIndexes($original, 'neutral');
        static::assertMatchCount(count($indexes));
        $types = [];
        foreach ($indexes as $index) {
            $types[] = static::TYPES[$index];
        }
        if (static::DISCRIMINATOR_PROPERTY !== '') {
            $selected = Discriminator::select($original, static::DISCRIMINATOR_PROPERTY, static::DISCRIMINATOR_MAPPING, static::class);
            if (!in_array($selected, $types, true)) {
                throw new \UnexpectedValueException('Discriminator target does not match the original schema at ' . static::class . '.');
            }
            $types = [$selected];
        }
        $matches = [];
        foreach ($types as $type) {
            $matches[] = Hydrator::hydrate($original, $type, false, static::class);
        }
        return new static($original, $matches);
    }

    /** @return list<int> */
    private static function matchingIndexes(mixed $value, string $mode): array
    {
        $indexes = [];
        foreach (static::BRANCH_SCHEMAS as $index => $schema) {
            if (SchemaValidator::matches($value, $schema, static::SCHEMA_DEFINITIONS, $mode)) {
                $indexes[] = $index;
            }
        }
        return $indexes;
    }

    private function currentValue(string $mode): mixed
    {
        // One selected mutable model is the outbound value. Several matching
        // variants remain alternative views of the original JSON snapshot.
        if (static::BRANCH_SCHEMAS !== [] && count($this->matches) === 1) {
            return SchemaValidator::snapshot(Serializer::value($this->matches[0], $mode));
        }
        return SchemaValidator::snapshot($this->original);
    }

    /** @return list<mixed> All successfully hydrated variants, in schema order. */
    public function matchedVariants(): array
    {
        return $this->matches;
    }

    /** @internal Used by the runtime itself; not covered by the backward-compatibility promise. */
    protected static function assertMatchCount(int $count): void
    {
        if ($count < 1) {
            throw new \UnexpectedValueException('Expected at least one anyOf match for ' . static::class . '.');
        }
    }

    /** @internal Used by the runtime itself; not covered by the backward-compatibility promise. */
    public function validateForMode(string $mode): void
    {
        foreach ($this->matches as $match) {
            DTO::validateNestedValue($match, $mode);
        }
        if (static::BRANCH_SCHEMAS !== []) {
            $value = $this->currentValue($mode);
            $indexes = self::matchingIndexes($value, $mode);
            static::assertMatchCount(count($indexes));
            if (static::DISCRIMINATOR_PROPERTY !== '') {
                $selected = Discriminator::select($value, static::DISCRIMINATOR_PROPERTY, static::DISCRIMINATOR_MAPPING, static::class);
                $match = $this->matches[0];
                if (!is_object($match) || !$match instanceof $selected) {
                    throw new \UnexpectedValueException('Mutated discriminator disagrees with the hydrated class at ' . static::class . '.');
                }
            }
        }
    }

    /** @internal Called after validation by the owning DTO or map. */
    public function serializeValidatedForMode(string $mode): mixed
    {
        return $this->currentValue($mode);
    }

    public function jsonSerialize(): mixed
    {
        $this->validateForMode('neutral');
        return $this->currentValue('neutral');
    }
}