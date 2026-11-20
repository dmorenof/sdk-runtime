<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/**
 * Validates the ID token an OpenID Connect provider issues (OpenID Connect Core 1.0 section 3.1.3.7), so you can trust
 * who signed in. The signature is checked with the provider's own keys (its `jwks_uri`), never with keys the token
 * carries, and only with the algorithms in {@see IdTokenOptions::$algorithms}.
 *
 *     $configuration = OpenIdDiscovery::forIssuer('https://accounts.example.com')->configuration();
 *     $validator = new IdTokenValidator($configuration, 'my-client-id');
 *     $idToken = $validator->validate($token->idToken, nonce: $request->nonce, accessToken: $token->accessToken);
 *     $idToken->subject();
 *
 * Checked: signature, `iss` (exactly the provider's), `aud` (holds your client id; with several audiences `azp` must be
 * it), `azp`, `exp`, `nbf` and `iat` (with a leeway), a present `sub`, the `nonce` you sent, and `at_hash` against the
 * access token it came with. A token that fails any of them is refused.
 */
final class IdTokenValidator
{
    /** Largest token read, in bytes. */
    private const int MAX_BYTES = 16384;

    private readonly JwkSet $keys;

    /**
     * @param OpenIdConfiguration $configuration The provider, from {@see OpenIdDiscovery}.
     * @param string $clientId Your client id: the audience the token must be for.
     * @throws OAuth2Exception When the provider publishes no `jwks_uri`.
     */
    public function __construct(
        private readonly OpenIdConfiguration $configuration,
        private readonly string $clientId,
        private readonly IdTokenOptions $options = new IdTokenOptions(),
    ) {
        if ($clientId === '') {
            throw new \InvalidArgumentException('The client id cannot be empty.');
        }
        if ($configuration->jwksUri === null) {
            throw new OAuth2Exception('The provider publishes no signing keys (jwks_uri).');
        }
        $this->keys = new JwkSet($configuration->jwksUri, $options);
    }

    /**
     * @param ?string $nonce The nonce you sent in the authorization request; the token must carry it.
     * @param ?string $accessToken The access token that came with the ID token; a token with `at_hash` must match it.
     * @throws IdTokenException The token is not valid.
     * @throws OAuth2Exception The provider's keys could not be fetched.
     */
    public function validate(string $idToken, ?string $nonce = null, ?string $accessToken = null): IdToken
    {
        [$header, $claims, $signingInput, $signature] = $this->parse($idToken);
        $algorithm = $header['alg'] ?? null;
        if (!is_string($algorithm) || !in_array($algorithm, $this->options->algorithms, true)) {
            throw new IdTokenException('The ID token is signed with an algorithm that is not accepted.');
        }
        if (isset($header['crit'])) {
            throw new IdTokenException('The ID token requires extensions this validator does not understand.');
        }
        $kid = $header['kid'] ?? null;
        if ($kid !== null && !is_string($kid)) {
            throw new IdTokenException('The ID token has an invalid key id.');
        }
        $this->assertSignature($algorithm, $kid, $signingInput, $signature);
        $this->assertClaims($claims, $nonce);
        if ($accessToken !== null && isset($claims['at_hash'])) {
            $this->assertAccessTokenHash($claims['at_hash'], $algorithm, $accessToken);
        }
        return new IdToken($claims);
    }

    /** @return array{array<mixed>, array<mixed>, string, string} */
    private function parse(string $token): array
    {
        $parts = explode('.', $token);
        if (strlen($token) > self::MAX_BYTES || count($parts) !== 3) {
            throw new IdTokenException('The ID token is not a compact JWS.');
        }
        $header = self::json($parts[0]);
        $claims = self::json($parts[1]);
        $signature = JwsAlgorithm::b64($parts[2]);
        if ($header === null || $claims === null || $signature === null) {
            throw new IdTokenException('The ID token is malformed.');
        }
        return [$header, $claims, $parts[0] . '.' . $parts[1], $signature];
    }

    /** @return array<mixed>|null */
    private static function json(string $segment): ?array
    {
        $bytes = JwsAlgorithm::b64($segment);
        $data = $bytes === null ? null : json_decode($bytes, true);
        return is_array($data) && !array_is_list($data) ? $data : null;
    }

    private function assertSignature(string $algorithm, ?string $kid, string $signingInput, string $signature): void
    {
        foreach ($this->keys->find($kid) as $key) {
            if (JwsAlgorithm::suits($algorithm, $key) && JwsAlgorithm::verify($algorithm, $key, $signingInput, $signature)) {
                return;
            }
        }
        throw new IdTokenException('The ID token signature is not valid for any of the provider\'s keys.');
    }

    /** @param array<mixed> $claims */
    private function assertClaims(array $claims, ?string $nonce): void
    {
        if (($claims['iss'] ?? null) !== $this->configuration->issuer) {
            throw new IdTokenException('The ID token was not issued by this provider.');
        }
        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || $sub === '' || strlen($sub) > 255) {
            throw new IdTokenException('The ID token has no valid subject.');
        }
        $this->assertAudience($claims['aud'] ?? null, $claims['azp'] ?? null);
        $now = $this->options->clock !== null ? ($this->options->clock)() : time();
        $leeway = $this->options->leeway;
        $exp = $claims['exp'] ?? null;
        $iat = $claims['iat'] ?? null;
        if (!is_int($exp) && !is_float($exp)) {
            throw new IdTokenException('The ID token has no valid exp.');
        }
        if (!is_int($iat) && !is_float($iat)) {
            throw new IdTokenException('The ID token has no valid iat.');
        }
        if ($exp + $leeway <= $now) {
            throw new IdTokenException('The ID token has expired.');
        }
        if ($iat - $leeway > $now) {
            throw new IdTokenException('The ID token was issued in the future.');
        }
        $nbf = $claims['nbf'] ?? null;
        if ($nbf !== null && (!(is_int($nbf) || is_float($nbf)) || $nbf - $leeway > $now)) {
            throw new IdTokenException('The ID token is not valid yet.');
        }
        if ($nonce !== null && !(is_string($claims['nonce'] ?? null) && hash_equals($nonce, $claims['nonce']))) {
            throw new IdTokenException('The ID token does not carry the nonce that was sent.');
        }
    }

    private function assertAudience(mixed $audience, mixed $authorizedParty): void
    {
        $audiences = is_string($audience) ? [$audience] : $audience;
        if (!is_array($audiences) || !array_is_list($audiences) || !in_array($this->clientId, $audiences, true)) {
            throw new IdTokenException('The ID token is not for this client.');
        }
        if ($authorizedParty !== null && $authorizedParty !== $this->clientId) {
            throw new IdTokenException('The ID token was issued to another client.');
        }
        if (count($audiences) > 1 && $authorizedParty === null) {
            throw new IdTokenException('The ID token has several audiences and no authorized party.');
        }
    }

    private function assertAccessTokenHash(mixed $atHash, string $algorithm, string $accessToken): void
    {
        $hash = (string) JwsAlgorithm::hash($algorithm);
        $digest = hash($hash, $accessToken, true);
        $expected = rtrim(strtr(base64_encode(substr($digest, 0, intdiv(strlen($digest), 2))), '+/', '-_'), '=');
        if (!is_string($atHash) || !hash_equals($expected, $atHash)) {
            throw new IdTokenException('The ID token does not match the access token it came with.');
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['issuer' => $this->configuration->issuer, 'clientId' => $this->clientId];
    }
}
