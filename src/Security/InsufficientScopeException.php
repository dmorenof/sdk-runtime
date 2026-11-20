<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/** The token you hold was not granted a scope the operation requires. Nothing was sent. */
final class InsufficientScopeException extends OAuth2Exception
{
    /**
     * @param string $scheme The security scheme whose token lacks the scopes.
     * @param list<string> $missing The scopes the operation requires and the token lacks.
     * @param list<string> $granted The scopes the token has.
     */
    public function __construct(
        public readonly string $scheme,
        public readonly array $missing,
        public readonly array $granted,
    ) {
        parent::__construct("The token for security scheme $scheme lacks the scope" . (count($missing) === 1 ? '' : 's') . ' this operation requires: ' . implode(' ', $missing) . '.');
    }
}
