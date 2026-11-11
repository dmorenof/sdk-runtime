<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Streaming;

use Dmorenof\SdkRuntime\Constraints;
use Dmorenof\SdkRuntime\JsonHydrator;
use Psr\Http\Message\StreamInterface;

/**
 * Lazy decoding of streamed responses: `application/jsonl` (and ndjson), `application/json-seq` and
 * `text/event-stream`. Each generator reads the PSR stream in chunks, yields items as they complete
 * and closes the stream when it finishes or is destroyed.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final class StreamDecoder
{
    /** Longest single line or record accepted, to bound memory on a misbehaving server. */
    public const int MAX_RECORD_BYTES = 1048576;

    private const int CHUNK = 8192;

    /**
     * @param array{type:?string,schemaNullable:bool,rules?:array<string,mixed>,encoding?:string} $entry
     * @return \Generator<int, mixed>
     */
    public static function iterate(StreamInterface $stream, array $entry, string $where): \Generator
    {
        return match ($entry['encoding'] ?? '') {
            'jsonl' => self::jsonl($stream, $entry, $where),
            'json-seq' => self::jsonSeq($stream, $entry, $where),
            'sse' => self::sse($stream, $entry, $where),
            default => throw new \InvalidArgumentException('Unsupported streaming encoding.'),
        };
    }

    /**
     * Lines terminated by LF, CRLF or CR; a final unterminated line is yielded too.
     *
     * @return \Generator<int, string>
     */
    public static function lines(StreamInterface $stream): \Generator
    {
        $buffer = '';
        try {
            while (true) {
                $chunk = self::read($stream);
                $eof = $chunk === '';
                $buffer .= $chunk;
                $length = strlen($buffer);
                $offset = 0;
                while ($offset < $length) {
                    $span = strcspn($buffer, "\r\n", $offset);
                    $position = $offset + $span;
                    if ($position >= $length) {
                        break; // no terminator yet
                    }
                    $width = 1;
                    if ($buffer[$position] === "\r") {
                        if ($position + 1 >= $length && !$eof) {
                            break; // may be the first half of CRLF: wait for the next byte
                        }
                        $width = ($position + 1 < $length && $buffer[$position + 1] === "\n") ? 2 : 1;
                    }
                    yield substr($buffer, $offset, $span);
                    $offset = $position + $width;
                }
                $buffer = (string) substr($buffer, $offset);
                self::assertBounded($buffer);
                if ($eof) {
                    if ($buffer !== '') {
                        yield $buffer;
                    }
                    return;
                }
            }
        } finally {
            $stream->close();
        }
    }

    /**
     * Records separated by a single byte; a final unterminated record is yielded too.
     *
     * @return \Generator<int, string>
     */
    private static function records(StreamInterface $stream, string $separator): \Generator
    {
        $buffer = '';
        try {
            while (true) {
                $chunk = self::read($stream);
                $buffer .= $chunk;
                while (($position = strpos($buffer, $separator)) !== false) {
                    $record = substr($buffer, 0, $position);
                    $buffer = (string) substr($buffer, $position + 1);
                    if ($record !== '') {
                        yield $record;
                    }
                }
                self::assertBounded($buffer);
                if ($chunk === '') {
                    if ($buffer !== '') {
                        yield $buffer;
                    }
                    return;
                }
            }
        } finally {
            $stream->close();
        }
    }

    /**
     * @param array{type:?string,schemaNullable:bool,rules?:array<string,mixed>} $entry
     * @return \Generator<int, mixed>
     */
    private static function jsonl(StreamInterface $stream, array $entry, string $where): \Generator
    {
        $index = 0;
        foreach (self::lines($stream) as $line) {
            if (trim($line) === '') {
                continue; // tolerated, though JSON Lines does not allow blank lines
            }
            yield $index => self::item($line, $entry, $where . '[' . $index . ']');
            $index++;
        }
    }

    /**
     * RFC 7464: each record starts with the RS byte (0x1E) and ends with a newline.
     *
     * @param array{type:?string,schemaNullable:bool,rules?:array<string,mixed>} $entry
     * @return \Generator<int, mixed>
     */
    private static function jsonSeq(StreamInterface $stream, array $entry, string $where): \Generator
    {
        $index = 0;
        foreach (self::records($stream, "\x1e") as $record) {
            if (trim($record) === '') {
                continue;
            }
            yield $index => self::item($record, $entry, $where . '[' . $index . ']');
            $index++;
        }
    }

    /**
     * HTML Living Standard "Server-sent events" parsing: comments are ignored, `data` lines are joined
     * with a newline, `id` persists until changed, and an event still incomplete at the end is discarded.
     *
     * @param array{type:?string,schemaNullable:bool,rules?:array<string,mixed>} $entry
     * @return \Generator<int, ServerSentEvent>
     */
    private static function sse(StreamInterface $stream, array $entry, string $where): \Generator
    {
        $index = 0;
        $first = true;
        $lastId = null;
        $event = '';
        $data = null;
        $retry = null;
        foreach (self::lines($stream) as $line) {
            if ($first) {
                $first = false;
                if (str_starts_with($line, "\xEF\xBB\xBF")) {
                    $line = substr($line, 3);
                }
            }
            if ($line === '') {
                if ($data !== null) {
                    $text = $data;
                    $item = ($entry['type'] ?? null) !== null && $text !== ''
                        ? self::item($text, $entry, $where . '[' . $index . ']')
                        : null;
                    yield $index => new ServerSentEvent($event === '' ? 'message' : $event, $text, $lastId, $retry, $item);
                    $index++;
                }
                $event = '';
                $data = null;
                $retry = null;
                continue;
            }
            if ($line[0] === ':') {
                continue;
            }
            $colon = strpos($line, ':');
            $field = $colon === false ? $line : substr($line, 0, $colon);
            $value = $colon === false ? '' : substr($line, $colon + 1);
            if (str_starts_with($value, ' ')) {
                $value = substr($value, 1);
            }
            switch ($field) {
                case 'event':
                    $event = $value;
                    break;
                case 'data':
                    $data = ($data === null ? '' : $data . "\n") . $value;
                    break;
                case 'id':
                    if (!str_contains($value, "\0")) {
                        $lastId = $value;
                    }
                    break;
                case 'retry':
                    if ($value !== '' && ctype_digit($value)) {
                        $retry = (int) $value;
                    }
                    break;
            }
        }
    }

    /** @param array{type:?string,schemaNullable:bool,rules?:array<string,mixed>} $entry */
    private static function item(string $json, array $entry, string $where): mixed
    {
        if ($entry['type'] === null) {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        }
        $item = JsonHydrator::decode($json, $entry['type'], $entry['schemaNullable']);
        Constraints::validate($item, $entry['rules'] ?? [], $where, 'response');
        return $item;
    }

    /** Next chunk; '' means the end. A stalled read is an error rather than a silent truncation. */
    private static function read(StreamInterface $stream): string
    {
        if ($stream->eof()) {
            return '';
        }
        $chunk = $stream->read(self::CHUNK);
        if ($chunk === '' && !$stream->eof()) {
            throw new \UnexpectedValueException('The response stream returned no data before ending (read timeout?).');
        }
        return $chunk;
    }

    private static function assertBounded(string $pending): void
    {
        if (strlen($pending) > self::MAX_RECORD_BYTES) {
            throw new \UnexpectedValueException('A streamed record exceeds ' . self::MAX_RECORD_BYTES . ' bytes.');
        }
    }
}
