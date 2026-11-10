<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

final readonly class OAuth2Token
{
    /** @param int|null $expiresAt Unix timestamp; null when the server declared no lifetime. */
    public function __construct(
        public string $accessToken,
        public ?int $expiresAt = null,
        public ?string $refreshToken = null,
    ) {
        if ($accessToken === '' || preg_match('/[\r\n]/', $accessToken) === 1) {
            throw new \InvalidArgumentException('Invalid OAuth2 access token.');
        }
    }

    public function isValid(int $now, int $leeway = 0): bool
    {
        return $this->expiresAt === null || $this->expiresAt - $leeway > $now;
    }

    /** @return array<string, string> Never exposes token values. */
    public function __debugInfo(): array
    {
        return ['expiresAt' => (string) $this->expiresAt, 'hasRefreshToken' => $this->refreshToken !== null ? 'yes' : 'no'];
    }
}
