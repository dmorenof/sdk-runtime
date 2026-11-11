<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/**
 * A started authorization-code flow. Redirect the user to `url`, and keep `state`, `codeVerifier` and
 * `expiresAt` (for example in the server-side session) until the callback arrives, then pass this object
 * to OAuth2AuthorizationCode::complete(). The verifier is a secret: never put it in the URL or in logs.
 */
final readonly class AuthorizationRequest
{
    public function __construct(
        public string $url,
        public string $state,
        public string $codeVerifier,
        public int $expiresAt,
    ) {}

    /** @return array<string, int> Neither the state nor the verifier is exposed. */
    public function __debugInfo(): array
    {
        return ['expiresAt' => $this->expiresAt];
    }
}
