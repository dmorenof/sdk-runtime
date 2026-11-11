<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Streaming;

/**
 * One event of a `text/event-stream` response, as defined by the HTML Living Standard.
 * `data` is the raw text (multiple `data:` lines joined with a newline); `item` holds the data decoded
 * as JSON into the operation's declared type, or null when the operation declares none.
 */
final readonly class ServerSentEvent
{
    public function __construct(
        public string $event,
        public string $data,
        public ?string $id = null,
        public ?int $retry = null,
        public mixed $item = null,
    ) {}
}
