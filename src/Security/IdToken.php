<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/** The claims of an ID token that passed {@see IdTokenValidator}. */
final readonly class IdToken
{
    /** @param array<string, mixed> $claims */
    public function __construct(public array $claims) {}

    public function subject(): string
    {
        return (string) $this->claims['sub'];
    }

    public function issuer(): string
    {
        return (string) $this->claims['iss'];
    }

    /** @return list<string> */
    public function audience(): array
    {
        $aud = $this->claims['aud'];
        return is_array($aud) ? array_values(array_filter($aud, 'is_string')) : [(string) $aud];
    }

    public function expiresAt(): int
    {
        return (int) $this->claims['exp'];
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
