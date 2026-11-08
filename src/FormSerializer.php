<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/** Form fields share the query expansion rules, with HTML form percent-encoding. */
final class FormSerializer
{
    /** @param array<string,'multi'|'csv'> $formats */
    public static function encode(DTO $body, array $formats = []): string
    {
        foreach ($formats as $format) {
            self::assertFormat($format);
        }
        $fields = [];
        foreach (get_object_vars($body->jsonSerializeForRequest()) as $name => $value) {
            $fields[$name] = self::field($value);
        }
        return QuerySerializer::encode($fields, $formats, PHP_QUERY_RFC1738);
    }

    private static function assertFormat(mixed $format): void
    {
        if (!in_array($format, ['multi', 'csv'], true)) {
            throw new \InvalidArgumentException('Only repeated-key and CSV form arrays are supported.');
        }
    }

    /** @return scalar|list<scalar>|null */
    private static function field(mixed $value): string|int|float|bool|array|null
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                throw new \UnexpectedValueException('Form array fields must be lists.');
            }
            $items = [];
            foreach ($value as $item) {
                $scalar = self::scalar($item);
                if ($scalar === null) {
                    throw new \UnexpectedValueException('Null form list items have no supported encoding.');
                }
                $items[] = $scalar;
            }
            return $items;
        }
        return self::scalar($value);
    }

    private static function scalar(mixed $value): string|int|float|bool|null
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value !== null && !is_scalar($value)) {
            throw new \UnexpectedValueException('Only scalar form fields and primitive lists are supported.');
        }
        return $value;
    }
}
