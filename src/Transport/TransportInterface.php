<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Transport;

interface TransportInterface
{
    /** @param array<string, string> $headers */
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse;
}
