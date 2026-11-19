<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/** The end user's own credentials, which the OAuth2 password grant sends to the token endpoint. */
final readonly class ResourceOwner
{
    public function __construct(
        public string $username,
        #[\SensitiveParameter] public string $password,
    ) {
        if ($username === '' || $password === '') {
            throw new \InvalidArgumentException('The username and password of a resource owner must be nonempty.');
        }
    }

    /** @return array<string, string> The password is never exposed. */
    public function __debugInfo(): array
    {
        return ['username' => $this->username];
    }
}
