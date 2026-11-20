<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/** The claims of an ID token that passed {@see IdTokenValidator}. */
final readonly class IdToken
{
    /** @param array<mixed> $claims */
    public function __construct(public array $claims) {}

    public function subject(): string
    {
        $sub = $this->claims['sub'] ?? null;
        return is_string($sub) ? $sub : '';
    }

    public function issuer(): string
    {
        $iss = $this->claims['iss'] ?? null;
        return is_string($iss) ? $iss : '';
    }

    /** @return list<string> */
    public function audience(): array
    {
        $aud = $this->claims['aud'] ?? null;
        return is_array($aud) ? array_values(array_filter($aud, 'is_string')) : (is_string($aud) ? [$aud] : []);
    }

    public function expiresAt(): int
    {
        $exp = $this->claims['exp'] ?? null;
        return is_int($exp) || is_float($exp) ? (int) $exp : 0;
    }

    /** A claim by name (`email`, `name`, ...), or null when the token does not carry it. */
    public function claim(string $name): mixed
    {
        return $this->claims[$name] ?? null;
    }

    /** @return array<string, string> Claims can hold personal data: only the subject is shown. */
    public function __debugInfo(): array
    {
        return ['subject' => $this->subject()];
    }
}
