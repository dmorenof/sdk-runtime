<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Shared typed-collection behavior without duplicating TypedArray validation.
 *
 * @extends \TypedArray\TypedArray<mixed>
 */
class TypedArray extends \TypedArray\TypedArray implements \Stringable
{
    /** @var array<string, mixed> Rules applied to every item (see Constraints). */
    protected const array ITEM_VALIDATION = [];
    /** @var array<string,mixed> */
    protected const array COLLECTION_VALIDATION = [];

    /** @param array<mixed> $items */
    public function __construct(array $items = [])
    {
        if (DTO::isReadingResponse()) {
            // An answer is trusted, and the hydrator typed every item as it built it (the generated code checked the lists
            // of scalars it hands over), so the library's own check of each item would only be the second.
            \ArrayObject::__construct($items);
            return;
        }
        foreach ($items as $item) {
            Constraints::validate($item, static::ITEM_VALIDATION, static::class . ' item');
        }
        parent::__construct($items);
        Constraints::validate($this, static::COLLECTION_VALIDATION, static::class);
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

    /** @internal Used by the runtime itself; not covered by the backward-compatibility promise. */
    public function validateForMode(string $mode = 'neutral'): void
    {
        Constraints::validate($this, static::COLLECTION_VALIDATION, static::class, $mode);
        foreach ($this as $item) {
            parent::validate($item);
            Constraints::validate($item, static::ITEM_VALIDATION, static::class . ' item', $mode);
        }
    }

    public function __toString(): string
    {
        return json_encode(Serializer::value($this, 'neutral'), JSON_THROW_ON_ERROR);
    }
}