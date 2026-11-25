<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\Security\IdTokenException;
use Dmorenof\SdkRuntime\Security\IdTokenOptions;
use Dmorenof\SdkRuntime\Security\IdTokenValidator;
use Dmorenof\SdkRuntime\Security\OAuth2Exception;
use Dmorenof\SdkRuntime\Security\OpenIdDiscovery;
use Dmorenof\SdkRuntime\Tests\Support\ScriptedServer;
use PHPUnit\Framework\TestCase;

/**
 * OpenID Connect on a real socket: the discovery document and the signing keys are fetched over HTTP with the default client from a
 * scripted server, and an ID token signed here (RS256) is validated against what was fetched. The other tests of the protocol use a fake
 * client and tokens signed by an independent implementation; these prove the fetching, the caching and the key rotation on the wire.
 */
final class RealOpenIdTest extends TestCase
{
    /** @var list<ScriptedServer> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
    }

    private function server(): ScriptedServer
    {
        return $this->servers[] = ScriptedServer::start();
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @return array{\OpenSSLAsymmetricKey, array<string, string>} */
    private static function key(string $kid): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        return [$key, ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid, 'n' => self::b64($rsa['n']), 'e' => self::b64($rsa['e'])]];
    }

    /** @param array<string, mixed> $claims */
    private static function sign(\OpenSSLAsymmetricKey $key, string $kid, array $claims): string
    {
        $input = self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid], JSON_THROW_ON_ERROR)) . '.' . self::b64(json_encode($claims, JSON_THROW_ON_ERROR));
        openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256);
        return $input . '.' . self::b64($signature);
    }

    /** @return array<string, mixed> */
    private static function document(ScriptedServer $server): array
    {
        return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode([
            'issuer' => $server->url(),
            'authorization_endpoint' => $server->url('/authorize'),
            'token_endpoint' => $server->url('/token'),
            'jwks_uri' => $server->url('/keys'),
        ], JSON_THROW_ON_ERROR)];
    }

    /** @param array<string, string> ...$keys @return array<string, mixed> */
    private static function jwks(array ...$keys): array
    {
        return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode(['keys' => $keys], JSON_THROW_ON_ERROR)];
    }

    /** @return array<string, mixed> */
    private static function claims(ScriptedServer $server): array
    {
        return ['iss' => $server->url(), 'sub' => 'user-1', 'aud' => 'client-123', 'iat' => time() - 5, 'exp' => time() + 600, 'email' => 'ada@example.com'];
    }

    public function testTheDiscoveryDocumentIsFetchedOnceAndCached(): void
    {
        $server = $this->server();
        $server->script([self::document($server)]);
        $discovery = OpenIdDiscovery::forUrl($server->url('/.well-known/openid-configuration'));

        $first = $discovery->configuration();
        $second = $discovery->configuration();

        self::assertSame($server->url('/token'), $first->tokenEndpoint);
        self::assertSame($server->url('/keys'), $first->jwksUri);
        self::assertSame($first, $second);
        self::assertCount(1, $server->requests());
        self::assertSame('GET /.well-known/openid-configuration', $server->requests()[0]['method'] . ' ' . $server->requests()[0]['target']);
    }

    public function testAnIssuerThatIsNotTheExpectedOneIsRefused(): void
    {
        $server = $this->server();
        $document = self::document($server);
        $document['body'] = str_replace(addcslashes($server->url(), '/'), 'https:\/\/evil.example', $document['body']);
        $server->script([$document]);

        $this->expectException(OAuth2Exception::class);
        OpenIdDiscovery::forIssuer($server->url())->configuration();
    }

    public function testADiscoveryErrorIsAnOAuth2Exception(): void
    {
        $server = $this->server();
        $server->script([['status' => 500, 'body' => 'down']]);

        $this->expectException(OAuth2Exception::class);
        OpenIdDiscovery::forUrl($server->url('/.well-known/openid-configuration'))->configuration();
    }

    public function testAnIdTokenIsValidatedAgainstTheKeysTheProviderPublishes(): void
    {
        [$private, $jwk] = self::key('k1');
        $server = $this->server();
        $server->script([self::document($server), self::jwks($jwk)]);
        $configuration = OpenIdDiscovery::forUrl($server->url('/.well-known/openid-configuration'))->configuration();
        $validator = new IdTokenValidator($configuration, 'client-123');

        $token = $validator->validate(self::sign($private, 'k1', self::claims($server)));
        $validator->validate(self::sign($private, 'k1', self::claims($server)));

        self::assertSame('user-1', $token->subject());
        self::assertSame('ada@example.com', $token->claim('email'));
        $targets = array_column($server->requests(), 'target');
        self::assertSame(['/.well-known/openid-configuration', '/keys'], $targets, 'The keys are fetched once and cached.');
    }

    public function testAKeyThatRotatedIsFetchedAgainButNotMoreThanOnceAMinute(): void
    {
        [$old, $oldJwk] = self::key('old');
        [$new, $newJwk] = self::key('new');
        $server = $this->server();
        $server->script([self::document($server), self::jwks($oldJwk), self::jwks($oldJwk, $newJwk)]);
        $now = time();
        $validator = new IdTokenValidator(
            OpenIdDiscovery::forUrl($server->url('/.well-known/openid-configuration'))->configuration(),
            'client-123',
            new IdTokenOptions(clock: static function () use (&$now): int {
                return $now;
            }),
        );

        $validator->validate(self::sign($old, 'old', self::claims($server)));
        try {
            $validator->validate(self::sign($new, 'new', self::claims($server)));
            self::fail('A key that appeared a few seconds after the last fetch must not be fetched for.');
        } catch (IdTokenException) {
            self::assertSame(['/.well-known/openid-configuration', '/keys'], array_column($server->requests(), 'target'));
        }

        $now += 61;
        $token = $validator->validate(self::sign($new, 'new', self::claims($server)));

        self::assertSame('user-1', $token->subject());
        self::assertSame(['/.well-known/openid-configuration', '/keys', '/keys'], array_column($server->requests(), 'target'));
    }

    public function testATokenSignedByAnotherKeyIsRefused(): void
    {
        [, $jwk] = self::key('k1');
        [$stranger] = self::key('k1');
        $server = $this->server();
        $server->script([self::document($server), self::jwks($jwk)]);
        $validator = new IdTokenValidator(OpenIdDiscovery::forUrl($server->url('/.well-known/openid-configuration'))->configuration(), 'client-123');

        $this->expectException(IdTokenException::class);
        $validator->validate(self::sign($stranger, 'k1', self::claims($server)));
    }

    public function testATokenForAnotherAudienceOrAnExpiredOneIsRefused(): void
    {
        [$private, $jwk] = self::key('k1');
        $server = $this->server();
        $server->script([self::document($server), self::jwks($jwk)]);
        $validator = new IdTokenValidator(OpenIdDiscovery::forUrl($server->url('/.well-known/openid-configuration'))->configuration(), 'client-123');

        foreach ([['aud' => 'someone-else'], ['exp' => time() - 3600, 'iat' => time() - 7200]] as $override) {
            try {
                $validator->validate(self::sign($private, 'k1', $override + self::claims($server)));
                self::fail('Expected an IdTokenException.');
            } catch (IdTokenException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
