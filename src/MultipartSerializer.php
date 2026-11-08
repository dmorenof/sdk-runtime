<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

use GuzzleHttp\Psr7\MultipartStream;
use Psr\Http\Message\UploadedFileInterface;

/** Adapt validated flat DTO fields to Guzzle's MIME stream without reading file contents. */
final class MultipartSerializer
{
    /**
     * @param array<string,array{contentType:?string,filename?:string}> $parts Wire field metadata.
     */
    public static function encode(DTO $body, array $parts): MultipartStream
    {
        foreach ($parts as $name => $part) {
            self::assertPart((string) $name, $part);
        }
        $elements = [];
        $usedStreams = [];
        foreach (get_object_vars($body->multipartSerializeForRequest()) as $name => $value) {
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
                $part = $parts[$name];
                if ($item instanceof \BackedEnum) {
                    $item = $item->value;
                }
                if ($item instanceof UploadedFileInterface) {
                    if (!isset($part['filename']) || $item->getError() !== UPLOAD_ERR_OK) {
                        throw new \UnexpectedValueException('Uploaded file requires a binary field and a successful upload.');
                    }
                    $part['filename'] = $item->getClientFilename() ?? $part['filename'];
                    $mime = $item->getClientMediaType();
                    if ($part['contentType'] !== null && $mime !== null && strcasecmp($mime, $part['contentType']) !== 0) {
                        throw new \UnexpectedValueException('Uploaded file content type conflicts with the declared encoding.');
                    }
                    $part['contentType'] ??= $mime ?? 'application/octet-stream';
                    self::assertPart((string) $name, $part);
                    $contents = $item->getStream();
                    if (!$contents->isReadable() || !$contents->isSeekable()) {
                        throw new \UnexpectedValueException('Uploaded file streams must be readable and seekable.');
                    }
                    $streamId = spl_object_id($contents);
                    if (isset($usedStreams[$streamId])) {
                        throw new \UnexpectedValueException('Multipart file parts must use independent stream cursors.');
                    }
                    $usedStreams[$streamId] = true;
                } else {
                    if (!is_scalar($item) || (is_float($item) && !is_finite($item))
                        || (isset($part['filename']) && !is_string($item))) {
                        throw new \UnexpectedValueException('Unsupported multipart field value.');
                    }
                    $contents = is_bool($item) ? ($item ? 'true' : 'false') : (string) $item;
                }
                $element = ['name' => (string) $name, 'contents' => $contents,
                    'headers' => ['Content-Type' => $part['contentType'] ?? 'application/octet-stream']];
                if (isset($part['filename'])) {
                    $element['filename'] = $part['filename'];
                }
                $elements[] = $element;
            }
        }
        return new MultipartStream($elements);
    }

    /** @param array{contentType:?string,filename?:string} $part */
    private static function assertPart(string $name, array $part): void
    {
        if (!self::quotedName($name) || ($part['contentType'] === null && !isset($part['filename']))
            || ($part['contentType'] !== null && !preg_match('~^[a-zA-Z0-9!#$&^_.+\-]+/[a-zA-Z0-9!#$&^_.+\-]+$~D', $part['contentType']))
            || (isset($part['filename']) && (!self::quotedName($part['filename']) || $part['filename'] === '0'))) {
            throw new \InvalidArgumentException('Unsupported multipart name or content type.');
        }
    }

    private static function quotedName(string $name): bool
    {
        return $name !== '' && preg_match('/^[\x20-\x21\x23-\x5B\x5D-\x7E]+$/D', $name) === 1;
    }
}
