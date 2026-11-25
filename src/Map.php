<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Shared keyed JSON-object collection behavior for generated map wrappers.
 *
 * A generated map says what it holds, so that static analysis knows the type of every value it gives:
 * `@extends \Dmorenof\SdkRuntime\Map<\Ns\DTOs\Item>`. Without it the value is `mixed`.
 *
 * @template TValue = mixed
 * @implements \ArrayAccess<string|int, TValue>
 * @implements \IteratorAggregate<string, TValue>
 */
class Map implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    protected const string VALUE_TYPE = 'mixed';
    protected const bool VALUE_NULLABLE = false;
    /** @var array<string, mixed> Rules applied to every value (see Constraints). */
    protected const array VALUE_VALIDATION = [];
    /** @var array<string,mixed> */
    protected const array MAP_VALIDATION = [];

    /** @var array<string, TValue> */
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
        if (!DTO::isReadingResponse()) {
            Constraints::validate($this, static::MAP_VALIDATION, static::class);
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($this->normalizeKey($offset), $this->values);
    }

    /** @return TValue|null */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->values[$this->normalizeKey($offset)] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $key = $this->normalizeKey($offset);
        $where = static::class . '[' . $key . ']';
        /** @var TValue $converted */
        $converted = Hydrator::hydrate($value, static::VALUE_TYPE, static::VALUE_NULLABLE, $where);
        if (!DTO::isReadingResponse()) {
            Constraints::validate($converted, static::VALUE_VALIDATION, $where);
        }
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

    /** @return \Traversable<string, TValue> */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->values);
    }

    /** @return array<string, TValue> */
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
        Constraints::validate($this, static::MAP_VALIDATION, static::class, $mode);
        foreach ($this->values as $key => $value) {
            $where = static::class . '[' . $key . ']';
            Constraints::validate($value, static::VALUE_VALIDATION, $where, $mode);
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

    /** @internal Used by the runtime itself; not covered by the backward-compatibility promise. */
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