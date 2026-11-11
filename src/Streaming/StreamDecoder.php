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
 *
 * @phpstan-import-type Representation from \Dmorenof\SdkRuntime\Operation
 */
final class StreamDecoder
{
    /** Longest single line or record accepted, to bound memory on a misbehaving server. */
    public const int MAX_RECORD_BYTES = 1048576;

    private const int CHUNK = 8192;

    /**
     * @param Representation $entry
     * @return \Generator<int, mixed>
     */
    public static function iterate(StreamInterface $stream, array $entry, string $where): \Generator
    {
        return match ($entry['encoding'] ?? '') {
            'jsonl' => self::items(self::lines($stream), $entry, $where),
            'json-seq' => self::items(self::records($stream, "\x1e"), $entry, $where), // RFC 7464: records start with RS
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
        $scanFrom = 0; // bytes at the start of $buffer already known to hold no terminator
        try {
            while (true) {
                $chunk = self::read($stream);
                $eof = $chunk === '';
                $buffer .= $chunk;
                $length = strlen($buffer);
                $offset = 0;
                while ($offset < $length) {
                    $start = $offset + $scanFrom;
                    $position = $start + strcspn($buffer, "\r\n", $start);
                    if ($position >= $length) {
                        $scanFrom = $length - $offset; // no terminator yet
                        break;
                    }
                    $width = 1;
                    if ($buffer[$position] === "\r") {
                        if ($position + 1 >= $length && !$eof) {
                            $scanFrom = $position - $offset; // may be the first half of CRLF: wait for the next byte
                            break;
                        }
                        $width = ($position + 1 < $length && $buffer[$position + 1] === "\n") ? 2 : 1;
                    }
                    yield substr($buffer, $offset, $position - $offset);
                    $offset = $position + $width;
                    $scanFrom = 0;
                }
                if ($offset > 0) {
                    $buffer = (string) substr($buffer, $offset);
                }
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
        $scanFrom = 0; // bytes at the start of $buffer already known to hold no separator
        try {
            while (true) {
                $chunk = self::read($stream);
                $buffer .= $chunk;
                $offset = 0;
                while (($position = strpos($buffer, $separator, $offset + $scanFrom)) !== false) {
                    $record = substr($buffer, $offset, $position - $offset);
                    $offset = $position + 1;
                    $scanFrom = 0;
                    if ($record !== '') {
                        yield $record;
                    }
                }
                if ($offset > 0) {
                    $buffer = (string) substr($buffer, $offset);
                }
                $scanFrom = strlen($buffer);
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
     * One hydrated item per non-blank record, keyed 0, 1, 2...
     *
     * @param iterable<string> $records
     * @param Representation $entry
     * @return \Generator<int, mixed>
     */
    private static function items(iterable $records, array $entry, string $where): \Generator
    {
        $index = 0;
        foreach ($records as $record) {
            if (trim($record) === '') {
                continue; // tolerated, though neither JSON Lines nor RFC 7464 allow empty records
            }
            yield $index => self::item($record, $entry, $where . '[' . $index . ']');
            $index++;
        }
    }

    /**
     * HTML Living Standard "Server-sent events" parsing: comments are ignored, `data` lines are joined
     * with a newline, `id` persists until changed, and an event still incomplete at the end is discarded.
     *
     * @param Representation $entry
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

    /** @param Representation $entry */
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
