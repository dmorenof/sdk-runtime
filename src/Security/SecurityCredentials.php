<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

final readonly class SecurityCredentials
{
    /** @param array<string, string|BasicAuthCredentials> $credentials */
    public function __construct(private array $credentials = [])
    {
        foreach ($credentials as $name => $credential) {
            if (!is_string($name) || $name === ''
                || (!is_string($credential) && !($credential instanceof BasicAuthCredentials))
                || $credential === ''
                || (is_string($credential) && preg_match('/[\r\n]/', $credential) === 1)) {
                throw new \InvalidArgumentException('Invalid security credentials map.');
            }
        }
    }

    public function forScheme(string $name): string|BasicAuthCredentials|null
    {
        return $this->credentials[$name] ?? null;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['configuredSchemes' => array_keys($this->credentials)];
    }
}