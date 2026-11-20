<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/** What an OpenID Connect provider says about itself in its discovery document (the parts an SDK uses). */
final readonly class OpenIdConfiguration
{
    /**
     * @param list<string> $codeChallengeMethods PKCE methods the provider supports; empty when it does not say.
     * @param list<string> $scopes Scopes the provider supports; empty when it does not say.
     * @param ?string $jwksUri Where the provider publishes the keys its ID tokens are signed with.
     * @param list<string> $idTokenSigningAlgorithms Algorithms the provider signs ID tokens with; empty when it does not say.
     */
    public function __construct(
        public string $issuer,
        public ?string $authorizationEndpoint,
        public ?string $tokenEndpoint,
        public array $codeChallengeMethods = [],
        public array $scopes = [],
        public ?string $jwksUri = null,
        public array $idTokenSigningAlgorithms = [],
    ) {}

    /**
     * The endpoints for the OAuth2 flows (client credentials, authorization code, password), with the issuer, which the
     * authorization code flow checks in the callback.
     *
     * @throws OAuth2Exception When the provider declares no token endpoint.
     */
    public function endpoints(): OAuth2Endpoints
    {
        if ($this->tokenEndpoint === null) {
            throw new OAuth2Exception('The provider declares no token endpoint.');
        }
        return new OAuth2Endpoints($this->tokenEndpoint, $this->authorizationEndpoint, $this->issuer);
    }

    /** Whether the provider accepts PKCE with S256, which the authorization code flow always uses. False means it says it does not. */
    public function supportsPkce(): bool
    {
        return $this->codeChallengeMethods === [] || in_array('S256', $this->codeChallengeMethods, true);
    }
}
