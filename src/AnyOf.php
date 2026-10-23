<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Shared runtime for OpenAPI anyOf/oneOf generated wrappers.
 * Generated subclasses provide only their permitted PHP types.
 */
class AnyOf implements \JsonSerializable
{
    /** @var list<class-string|class-string-enum|'string'|'int'|'float'|'bool'|'null'> */
    protected const array TYPES = [];

    /** @param list<mixed> $matches */
    protected function __construct(
        private readonly mixed $original,
        private readonly array $matches,
    ) {}

    public static function fromValue(mixed $value): static
    {
        $original = $value instanceof \JsonSerializable ? $value->jsonSerialize() : $value;
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

    /** @return list<mixed> All successfully hydrated variants, in schema order. */
    public function matchedVariants(): array
    {
        return $this->matches;
    }

    protected static function assertMatchCount(int $count): void
    {
        if ($count < 1) {
            throw new \UnexpectedValueException('Expected at least one anyOf match for ' . static::class . '.');
        }
    }

    public function validateForMode(string $mode): void
    {
        foreach ($this->matches as $match) {
            DTO::validateNestedValue($match, $mode);
        }
    }

    /** @internal Called after validation by the owning DTO or map. */
    public function serializeValidatedForMode(string $mode): mixed
    {
        return $this->original;
    }

    public function jsonSerialize(): mixed
    {
        $this->validateForMode('neutral');
        return $this->original;
    }
}