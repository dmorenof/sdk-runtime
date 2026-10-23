<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Transport;

final readonly class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(public int $status, public string $body, public array $headers = []) {}
}
