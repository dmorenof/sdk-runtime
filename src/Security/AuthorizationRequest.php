<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/**
 * A started authorization-code flow. Redirect the user to `url`, and keep `state`, `codeVerifier` and
 * `expiresAt` (for example in the server-side session) until the callback arrives, then pass this object
 * to OAuth2AuthorizationCode::complete(). The verifier is a secret: never put it in the URL or in logs.
 * Keep it with toArray() and bring it back with fromArray().
 */
final readonly class AuthorizationRequest
{
    public function __construct(
        public string $url,
        public string $state,
        public string $codeVerifier,
        public int $expiresAt,
    ) {}

    /**
     * What to keep between the redirect and the callback (the URL is not needed to finish the flow).
     * The result holds the PKCE verifier: store it server-side, never in a cookie, URL or log.
     *
     * @return array{state: string, codeVerifier: string, expiresAt: int}
     */
    public function toArray(): array
    {
        return ['state' => $this->state, 'codeVerifier' => $this->codeVerifier, 'expiresAt' => $this->expiresAt];
    }

    /**
     * Rebuilds a request from toArray() output, for the callback.
     *
     * @param array<mixed> $data
     * @throws \InvalidArgumentException When the data is not what toArray() produced.
     */
    public static function fromArray(array $data): self
    {
        $state = $data['state'] ?? null;
        $verifier = $data['codeVerifier'] ?? null;
        $expiresAt = $data['expiresAt'] ?? null;
        if (!is_string($state) || $state === '' || !is_string($verifier) || !is_int($expiresAt)) {
            throw new \InvalidArgumentException('Stored authorization request is incomplete: expected state, codeVerifier and expiresAt.');
        }
        return new self('', $state, $verifier, $expiresAt);
    }

    /** @return array<string, int> Neither the state nor the verifier is exposed. */
    public function __debugInfo(): array
    {
        return ['expiresAt' => $this->expiresAt];
    }
}
