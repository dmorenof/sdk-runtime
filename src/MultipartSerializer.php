<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

use GuzzleHttp\Psr7\MultipartStream;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Adapt validated flat DTO fields to Guzzle's MIME stream without reading file contents.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final class MultipartSerializer
{
    /**
     * @param array<string,array{contentType:?string,filename?:string,json?:bool}> $parts Wire field metadata.
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
            if (!array_is_list($values)) {
                throw new \UnexpectedValueException('Multipart array fields must be lists.');
            }
            foreach ($values as $item) {
                $elements[] = self::element((string) $name, $item, $parts[$name], $usedStreams);
            }
        }
        return new MultipartStream($elements);
    }

    /**
     * One multipart element for a value: a JSON part, an uploaded file, or a scalar field.
     *
     * @param array{contentType:?string,filename?:string,json?:bool} $part
     * @param array<int, true> $usedStreams Stream ids already in use, so no two file parts share a cursor.
     * @return array{name: string, contents: string|\Psr\Http\Message\StreamInterface, headers: array<string, string>, filename?: string}
     */
    private static function element(string $name, mixed $item, array $part, array &$usedStreams): array
    {
        if ($item instanceof \BackedEnum) {
            $item = $item->value;
        }
        if ($part['json'] ?? false) {
            if ($item instanceof UploadedFileInterface) {
                throw new \UnexpectedValueException('Uploaded files cannot be encoded as JSON parts.');
            }
            $contents = json_encode($item, JSON_THROW_ON_ERROR);
        } elseif ($item instanceof UploadedFileInterface) {
            [$contents, $part] = self::uploadedFile($name, $item, $part, $usedStreams);
        } else {
            if (!is_scalar($item) || (is_float($item) && !is_finite($item))
                || (isset($part['filename']) && !is_string($item))) {
                throw new \UnexpectedValueException('Unsupported multipart field value.');
            }
            $contents = is_bool($item) ? ($item ? 'true' : 'false') : (string) $item;
        }
        $element = ['name' => $name, 'contents' => $contents,
            'headers' => ['Content-Type' => $part['contentType'] ?? 'application/octet-stream']];
        if (isset($part['filename'])) {
            $element['filename'] = $part['filename'];
        }
        return $element;
    }

    /**
     * Checks an uploaded file against its declared part and completes the part from the file's own name and type.
     *
     * @param array{contentType:?string,filename?:string,json?:bool} $part
     * @param array<int, true> $usedStreams
     * @return array{\Psr\Http\Message\StreamInterface, array{contentType:?string,filename?:string,json?:bool}}
     */
    private static function uploadedFile(string $name, UploadedFileInterface $file, array $part, array &$usedStreams): array
    {
        if (!isset($part['filename']) || $file->getError() !== UPLOAD_ERR_OK) {
            throw new \UnexpectedValueException('Uploaded file requires a binary field and a successful upload.');
        }
        $part['filename'] = $file->getClientFilename() ?? $part['filename'];
        $mime = $file->getClientMediaType();
        if ($part['contentType'] !== null && $mime !== null && strcasecmp($mime, $part['contentType']) !== 0) {
            throw new \UnexpectedValueException('Uploaded file content type conflicts with the declared encoding.');
        }
        $part['contentType'] ??= $mime ?? 'application/octet-stream';
        self::assertPart($name, $part);
        $stream = $file->getStream();
        if (!$stream->isReadable() || !$stream->isSeekable()) {
            throw new \UnexpectedValueException('Uploaded file streams must be readable and seekable.');
        }
        $streamId = spl_object_id($stream);
        if (isset($usedStreams[$streamId])) {
            throw new \UnexpectedValueException('Multipart file parts must use independent stream cursors.');
        }
        $usedStreams[$streamId] = true;
        return [$stream, $part];
    }

    /** @param array{contentType:?string,filename?:string,json?:mixed} $part */
    private static function assertPart(string $name, array $part): void
    {
        if ((isset($part['json']) && (!is_bool($part['json']) || ($part['json'] && (isset($part['filename'])
                || preg_match('~^application/(?:[a-zA-Z0-9!#$&^_.+\\-]+\\+)?json$~D', $part['contentType'] ?? '') !== 1))))
            || !self::quotedName($name) || ($part['contentType'] === null && !isset($part['filename']))
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
