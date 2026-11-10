<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/** Shared typed-collection behavior without duplicating TypedArray validation. */
class TypedArray extends \TypedArray\TypedArray implements \Stringable
{
    /** @var array<string, mixed> Rules applied to every item (see Constraints). */
    protected const array ITEM_VALIDATION = [];
    /** @var array<string,mixed> */
    protected const array COLLECTION_VALIDATION = [];

    /** @param array<mixed> $items */
    public function __construct(array $items = [])
    {
        foreach ($items as $item) {
            Constraints::validate($item, static::ITEM_VALIDATION, static::class . ' item');
        }
        parent::__construct($items);
        $this->validateForMode('neutral');
    }

    public function getExpectedType(): string
    {
        return $this->expected_type;
    }

    public function append(mixed $value): void
    {
        Constraints::validate($value, static::ITEM_VALIDATION, static::class . ' item');
        parent::append($value);
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        Constraints::validate($value, static::ITEM_VALIDATION, static::class . ' item');
        parent::offsetSet($key, $value);
    }

    public function validateForMode(string $mode = 'neutral'): void
    {
        Constraints::validate($this, static::COLLECTION_VALIDATION, static::class, $mode);
    }

    public function __toString(): string
    {
        DTO::validateNestedValue($this, 'neutral');
        return json_encode(Serializer::value($this, 'neutral'), JSON_THROW_ON_ERROR);
    }
}