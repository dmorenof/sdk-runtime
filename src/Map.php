<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Shared keyed JSON-object collection behavior for generated map wrappers.
 *
 * @implements \ArrayAccess<string|int, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
class Map implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    protected const string VALUE_TYPE = 'mixed';
    protected const bool VALUE_NULLABLE = false;
    /** @var array<string, mixed> Rules applied to every value (see Constraints). */
    protected const array VALUE_VALIDATION = [];

    /** @var array<string, mixed> */
    private array $values = [];

    /** @param array<mixed>|object $values */
    public function __construct(array|object $values = [])
    {
        if (is_array($values) && $values !== [] && array_is_list($values)) {
            throw new \UnexpectedValueException('Expected an associative array or JSON object for a map.');
        }
        foreach (is_object($values) ? get_object_vars($values) : $values as $key => $value) {
            $this->offsetSet($key, $value);
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($this->normalizeKey($offset), $this->values);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->values[$this->normalizeKey($offset)] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $key = $this->normalizeKey($offset);
        $where = static::class . '[' . $key . ']';
        $converted = Hydrator::hydrate($value, static::VALUE_TYPE, static::VALUE_NULLABLE, $where);
        Constraints::validate($converted, static::VALUE_VALIDATION, $where);
        $this->values[$key] = $converted;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->values[$this->normalizeKey($offset)]);
    }

    public function count(): int
    {
        return count($this->values);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->values);
    }

    /** @return array<string, mixed> */
    public function getArrayCopy(): array
    {
        return $this->values;
    }

    public function validate(): void
    {
        $this->validateForMode('neutral');
    }

    public function validateForRequest(): void
    {
        $this->validateForMode('request');
    }

    public function validateForResponse(): void
    {
        $this->validateForMode('response');
    }

    private function validateForMode(string $mode): void
    {
        foreach ($this->values as $key => $value) {
            $where = static::class . '[' . $key . ']';
            Constraints::validate($value, static::VALUE_VALIDATION, $where);
            DTO::validateNestedValue($value, $mode);
        }
    }

    private function normalizeKey(mixed $key): string
    {
        if (!is_string($key) && !is_int($key)) {
            throw new \InvalidArgumentException('Map keys must be strings or integers.');
        }
        return (string) $key;
    }

    public function jsonSerialize(): object
    {
        return $this->serializeForMode('neutral');
    }

    public function serializeForMode(string $mode): object
    {
        $this->validateForMode($mode);
        return $this->serializeValidatedForMode($mode);
    }

    /** @internal The parent DTO has already validated this map. */
    public function serializeValidatedForMode(string $mode): object
    {
        $values = [];
        foreach ($this->values as $key => $value) {
            $values[$key] = Serializer::value($value, $mode);
        }
        return (object) $values;
    }
}