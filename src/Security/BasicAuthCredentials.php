<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

final readonly class BasicAuthCredentials
{
    private string $username;
    private string $password;

    public function __construct(
        string $username,
        string $password,
    ) {
        if (str_contains($username, ':') || preg_match('/[\r\n]/', $username . $password) === 1) {
            throw new \InvalidArgumentException('Invalid HTTP Basic credentials.');
        }
        $this->username = $username;
        $this->password = $password;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function password(): string
    {
        return $this->password;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['credentials' => '[redacted]'];
    }
}