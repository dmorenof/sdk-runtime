<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Transport;

use Dmorenof\SdkRuntime\Security\ClientCertificate;

/**
 * How one call is handed to the HTTP client: whether the answer body is streamed, and which client certificate the
 * TLS handshake presents (none for a hop that left the origin of the call).
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final readonly class SendOptions
{
    public function __construct(
        public bool $stream = false,
        public ?ClientCertificate $certificate = null,
    ) {}

    public function withoutCertificate(): self
    {
        return $this->certificate === null ? $this : new self($this->stream);
    }
}
