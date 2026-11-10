<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Transport;

/** Status and headers of the most recent response received by a client. */
final readonly class ResponseInfo
{
    public function __construct(
        public int $status,
        public ResponseHeaders $headers,
        public string $operation,
    ) {}
}
