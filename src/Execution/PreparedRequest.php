<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Execution;

use Psr\Http\Message\StreamInterface;

/**
 * A call ready to send: expanded path, query values, string headers and the body bytes.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final readonly class PreparedRequest
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $path,
        public array $query,
        public array $headers,
        public string|StreamInterface|null $body,
    ) {}
}
