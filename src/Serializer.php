<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * Turns already validated models, collections, maps and unions back into JSON-ready values.
 * The mode ('request', 'response' or 'neutral') selects which directional fields are omitted.
 */
final class Serializer
{
    public static function value(mixed $value, string $mode, bool $multipart = false): mixed
    {
        if ($value instanceof \Psr\Http\Message\StreamInterface) {
            throw new \UnexpectedValueException('PSR streams require a binary request body.');
        }
        if ($value instanceof \Psr\Http\Message\UploadedFileInterface) {
            if ($multipart) {
                return $value;
            }
            throw new \UnexpectedValueException('Uploaded files require multipart serialization.');
        }
        if ($value instanceof DTO) {
            $effective = $mode === 'neutral'
                ? ($value instanceof Request ? 'request' : ($value instanceof Response ? 'response' : 'neutral'))
                : $mode;
            return $value->serializeValidated($effective);
        }
        if ($value instanceof TypedArray) {
            $items = [];
            foreach ($value as $item) {
                $items[] = self::value($item, $mode, $multipart);
            }
            return $items;
        }
        if ($value instanceof Map || $value instanceof AnyOf) {
            return $value->serializeValidatedForMode($mode);
        }
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::value($item, $mode, $multipart), $value);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $excluded Direction-specific fields to omit.
     * @param array<string, string> $wireNames PHP property name => JSON name.
     */
    public static function fields(array $fields, array $excluded, array $wireNames, string $mode, bool $multipart = false): object
    {
        $serialized = [];
        foreach ($fields as $name => $value) {
            if (!in_array($name, $excluded, true)) {
                $serialized[$wireNames[$name] ?? $name] = self::value($value, $mode, $multipart);
            }
        }
        return (object) $serialized;
    }
}
