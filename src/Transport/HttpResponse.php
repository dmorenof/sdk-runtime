<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Transport;

final readonly class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(public int $status, public string $body, public array $headers = []) {}

    /** Materialize only the legacy/JSON path; binary PSR responses retain their stream. */
    public static function fromPsr(\Psr\Http\Message\ResponseInterface $response): self
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }
        return new self($response->getStatusCode(), (string) $response->getBody(), $headers);
    }
}
