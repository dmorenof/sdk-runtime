<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

final readonly class SecurityCredentials
{
    /** @var array<string, string|BasicAuthCredentials> */
    private array $credentials;

    /** @param array<mixed> $credentials Scheme name => token or API key, or BasicAuthCredentials for HTTP Basic. */
    public function __construct(array $credentials = [])
    {
        $valid = [];
        foreach ($credentials as $name => $credential) {
            if (!is_string($name) || $name === ''
                || (!is_string($credential) && !($credential instanceof BasicAuthCredentials))
                || $credential === ''
                || (is_string($credential) && preg_match('/[\r\n]/', $credential) === 1)) {
                throw new \InvalidArgumentException('Invalid security credentials map.');
            }
            $valid[$name] = $credential;
        }
        $this->credentials = $valid;
    }

    public function forScheme(string $name): string|BasicAuthCredentials|null
    {
        return $this->credentials[$name] ?? null;
    }

    /** @return array<string, list<string>> Only which schemes are configured, never the secrets. */
    public function __debugInfo(): array
    {
        return ['configuredSchemes' => array_keys($this->credentials)];
    }
}