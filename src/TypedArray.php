<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/** Shared typed-collection behavior without duplicating TypedArray validation. */
class TypedArray extends \TypedArray\TypedArray implements \Stringable
{
    /** @var array<string, mixed> Rules applied to every item (see Constraints). */
    protected const array ITEM_VALIDATION = [];

    /** @param array<mixed> $items */
    public function __construct(array $items = [])
    {
        foreach ($items as $item) {
            Constraints::validate($item, static::ITEM_VALIDATION, static::class . ' item');
        }
        parent::__construct($items);
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

    public function __toString(): string
    {
        return json_encode($this->getArrayCopy(), JSON_THROW_ON_ERROR);
    }
}