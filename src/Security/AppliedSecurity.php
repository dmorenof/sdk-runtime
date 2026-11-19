<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/**
 * What an operation's security requirement adds to a request: headers, query parameters, and the token
 * providers it used (so a 401 can invalidate their tokens).
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final readonly class AppliedSecurity
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     * @param list<TokenProviderInterface> $providers
     * @param ClientCertificate|null $certificate The certificate the TLS handshake of the call presents (mutualTLS).
     */
    public function __construct(
        public array $headers = [],
        public array $query = [],
        public array $providers = [],
        public ?ClientCertificate $certificate = null,
    ) {}
}
