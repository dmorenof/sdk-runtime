<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/**
 * A token provider that knows which scopes its token was granted, so a call that needs scopes the token lacks fails
 * before it is sent. A provider that does not implement this (or returns null) is trusted: nothing is checked.
 */
interface ScopeAwareTokenProvider extends TokenProviderInterface
{
    /**
     * The scopes of the current token, or null when they are not known (no token yet, or the server did not say and
     * none were requested). Call it after accessToken(), which is what obtains the token.
     *
     * @return list<string>|null
     */
    public function grantedScopes(): ?array;
}
