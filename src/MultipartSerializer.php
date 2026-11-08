<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

use GuzzleHttp\Psr7\MultipartStream;

/** Adapt validated flat DTO fields to Guzzle's MIME encoder. Bodies are buffered. */
final class MultipartSerializer
{
    /**
     * @param array<string,array{contentType:string,filename?:string}> $parts Wire field metadata.
     */
    public static function encode(DTO $body, array $parts): MultipartStream
    {
        foreach ($parts as $name => $part) {
            if (!self::quotedName($name) || !preg_match('~^[a-zA-Z0-9!#$&^_.+\-]+/[a-zA-Z0-9!#$&^_.+\-]+$~D', $part['contentType'])
                || (isset($part['filename']) && !self::quotedName($part['filename']))) {
                throw new \InvalidArgumentException('Unsupported multipart name or content type.');
            }
        }
        $elements = [];
        foreach (get_object_vars($body->jsonSerializeForRequest()) as $name => $value) {
            if (!isset($parts[$name])) {
                throw new \InvalidArgumentException("Missing multipart metadata for $name.");
            }
            if ($value === null) {
                continue;
            }
            $values = is_array($value) ? $value : [$value];
            if (!array_is_list($values) || (isset($parts[$name]['filename']) && is_array($value))) {
                throw new \UnexpectedValueException('Multipart fields must be scalars or primitive lists; files must be byte strings.');
            }
            foreach ($values as $item) {
                if ($item instanceof \BackedEnum) {
                    $item = $item->value;
                }
                if (!is_scalar($item) || (is_float($item) && !is_finite($item))
                    || (isset($parts[$name]['filename']) && !is_string($item))) {
                    throw new \UnexpectedValueException('Unsupported multipart field value.');
                }
                $element = ['name' => $name, 'contents' => is_bool($item) ? ($item ? 'true' : 'false') : (string) $item,
                    'headers' => ['Content-Type' => $parts[$name]['contentType']]];
                if (isset($parts[$name]['filename'])) {
                    $element['filename'] = $parts[$name]['filename'];
                }
                $elements[] = $element;
            }
        }
        return new MultipartStream($elements);
    }

    private static function quotedName(string $name): bool
    {
        return $name !== '' && preg_match('/^[\x20-\x21\x23-\x5B\x5D-\x7E]+$/D', $name) === 1;
    }
}
