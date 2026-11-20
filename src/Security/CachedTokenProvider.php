<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/**
 * The token lifecycle every OAuth2 provider shares: reuse a valid stored token, renew an expired one with its
 * refresh token, and fall back to the grant when there is nothing to renew. A provider supplies only what makes
 * it different: how to obtain a token (obtain) and what a failed renewal means (refreshFailed).
 *
 * @internal Extension point for the runtime's own providers: not covered by the backward-compatibility promise
 *           (see docs/PUBLIC_API.md). Its public methods are part of the providers that extend it.
 */
abstract class CachedTokenProvider implements ScopeAwareTokenProvider
{
    private readonly TokenStoreInterface $store;

    protected readonly OAuth2TokenEndpoint $endpoint;
    private readonly string $storeKey;
    private readonly int $leeway;

    /** Providers differ only in the grant they use; everything else comes from the same three value objects. */
    public function __construct(
        protected readonly OAuth2Endpoints $endpoints,
        protected readonly OAuth2Client $client,
        protected readonly OAuth2Options $options,
    ) {
        $this->endpoint = new OAuth2TokenEndpoint($endpoints, $client, $options);
        $this->store = $options->store ?? new InMemoryTokenStore();
        $this->leeway = $options->leeway;
        $this->storeKey = self::storeKey(static::class, $endpoints->tokenUrl, $client->id, implode(' ', $options->scopes), $options->subject);
    }

    final public function accessToken(): string
    {
        $token = $this->stored();
        if ($token !== null && $token->isValid($this->endpoint->now(), $this->leeway)) {
            return $token->accessToken;
        }
        if ($token?->refreshToken !== null) {
            try {
                return $this->save($this->endpoint->request(['grant_type' => 'refresh_token', 'refresh_token' => $token->refreshToken], $token->refreshToken, $token->scopes));
            } catch (OAuth2Exception $failure) {
                $this->refreshFailed($failure);
            }
        }
        return $this->save($this->obtain($token));
    }

    final public function grantedScopes(): ?array
    {
        return $this->stored()?->scopes;
    }

    final public function invalidate(): void
    {
        $token = $this->stored();
        if ($token?->refreshToken !== null) {
            // Keep the refresh token but force renewal on the next call.
            $this->store->set($this->storeKey, new OAuth2Token($token->accessToken, 0, $token->refreshToken));
            return;
        }
        $this->discard();
    }

    /** Forgets the stored token. */
    final public function forget(): void
    {
        $this->discard();
    }

    /**
     * A token for when none can be reused or renewed; $stale is the expired token that could not be renewed.
     *
     * @throws OAuth2Exception
     */
    abstract protected function obtain(?OAuth2Token $stale): OAuth2Token;

    /**
     * Renewing with the refresh token failed. Return to forget the token and go on to obtain(); throw to stop.
     */
    protected function refreshFailed(OAuth2Exception $failure): void
    {
        $this->discard();
    }

    protected function stored(): ?OAuth2Token
    {
        return $this->store->get($this->storeKey);
    }

    protected function save(OAuth2Token $token): string
    {
        $this->store->set($this->storeKey, $token);
        return $token->accessToken;
    }

    protected function discard(): void
    {
        $this->store->delete($this->storeKey);
    }

    /** A store key from the parts that tell one token apart from another. */
    protected static function storeKey(string ...$parts): string
    {
        return hash('sha256', implode("\0", $parts));
    }
}
