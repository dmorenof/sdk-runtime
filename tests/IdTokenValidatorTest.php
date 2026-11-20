<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\Security\IdTokenException;
use Dmorenof\SdkRuntime\Security\IdTokenOptions;
use Dmorenof\SdkRuntime\Security\IdTokenValidator;
use Dmorenof\SdkRuntime\Security\OAuth2Exception;
use Dmorenof\SdkRuntime\Security\OpenIdConfiguration;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Tokens in tests/fixtures/id-tokens.json were signed by PyJWT, independent of this code; the rest are signed here. */
final class IdTokenValidatorTest extends TestCase
{
    private const string JWKS_URI = 'https://login.example.com/tenant/keys';

    /** @var array<string, mixed> */
    private array $fx;
    /** @var \OpenSSLAsymmetricKey */
    private $privateKey;
    /** @var array<string, string> */
    private array $publicJwk;
    private int $now;

    protected function setUp(): void
    {
        $this->fx = json_decode((string) file_get_contents(__DIR__ . '/fixtures/id-tokens.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->now = $this->fx['now'];
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        $this->privateKey = $key;
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $this->publicJwk = ['kty' => 'RSA', 'kid' => 'mine', 'n' => self::b64($rsa['n']), 'e' => self::b64($rsa['e'])];
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @param list<ResponseInterface> $responses */
    private function http(array $responses): ClientInterface
    {
        return new class ($responses) implements ClientInterface {
            public int $calls = 0;

            /** @param list<ResponseInterface> $responses */
            public function __construct(private array $responses) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->calls++;
                return array_shift($this->responses) ?? throw new \LogicException('No scripted response left.');
            }
        };
    }

    private static function jwks(array ...$keys): Response
    {
        return new Response(200, [], json_encode(['keys' => $keys], JSON_THROW_ON_ERROR));
    }

    /** @param list<ResponseInterface> $responses */
    private function validator(array $responses, array $options = [], ?string $issuer = null, string $client = 'client-123'): IdTokenValidator
    {
        $configuration = new OpenIdConfiguration($issuer ?? $this->fx['issuer'], null, null, [], [], self::JWKS_URI);
        $http = $this->http($responses);
        $options = new IdTokenOptions(...($options + ['http' => $http, 'clock' => fn(): int => $this->now]));
        return new IdTokenValidator($configuration, $client, $options);
    }

    /** @param array<string, mixed> $claims @param array<string, mixed> $header */
    private function sign(array $claims = [], array $header = []): string
    {
        $claims += ['iss' => $this->fx['issuer'], 'sub' => 'u1', 'aud' => 'client-123', 'exp' => $this->now + 600, 'iat' => $this->now - 10];
        $claims = array_filter($claims, static fn(mixed $v): bool => $v !== null);
        $header += ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'mine'];
        $input = self::b64(json_encode($header, JSON_THROW_ON_ERROR)) . '.' . self::b64(json_encode($claims, JSON_THROW_ON_ERROR));
        openssl_sign($input, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        return $input . '.' . self::b64($signature);
    }

    /** @return iterable<string, array{string}> */
    public static function algorithms(): iterable
    {
        foreach (['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'ES512'] as $alg) {
            yield $alg => [$alg];
        }
    }

    #[DataProvider('algorithms')]
    public function testAcceptsTokensSignedByAnIndependentImplementation(string $algorithm): void
    {
        $validator = $this->validator([self::jwks(...$this->fx['jwks']['keys'])]);
        $token = $validator->validate($this->fx['tokens'][$algorithm], $this->fx['nonce'], $this->fx['accessToken']);
        self::assertSame('user-42', $token->subject());
        self::assertSame($this->fx['issuer'], $token->issuer());
        self::assertSame(['client-123'], $token->audience());
        self::assertSame('ada@example.com', $token->claim('email'));
        self::assertNull($token->claim('missing'));
        self::assertSame($this->now + 600, $token->expiresAt());
    }

    #[DataProvider('algorithms')]
    public function testRefusesATamperedPayloadOrSignature(string $algorithm): void
    {
        $parts = explode('.', $this->fx['tokens'][$algorithm]);
        $forged = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        $forged['sub'] = 'admin';
        $payload = self::b64(json_encode($forged, JSON_THROW_ON_ERROR));
        $flipped = $parts[2];
        $flipped[5] = $flipped[5] === 'A' ? 'B' : 'A';
        foreach ([$parts[0] . '.' . $payload . '.' . $parts[2], $parts[0] . '.' . $parts[1] . '.' . $flipped] as $token) {
            $validator = $this->validator([self::jwks(...$this->fx['jwks']['keys'])]);
            try {
                $validator->validate($token);
                self::fail('A forged token was accepted.');
            } catch (IdTokenException $refused) {
                self::assertStringContainsString('signature', $refused->getMessage());
            }
        }
    }

    public function testRefusesATokenSignedByAnotherKeyEvenWithTheSameKid(): void
    {
        $other = $this->fx['jwks']['keys'][0];
        $validator = $this->validator([self::jwks(['kid' => 'mine'] + $other)]);
        $this->expectException(IdTokenException::class);
        $validator->validate($this->sign());
    }

    public function testRefusesAnAlgorithmThatIsNotAccepted(): void
    {
        $validator = $this->validator([self::jwks($this->publicJwk)]);
        foreach (['none', 'HS256', 'PS256', 'rs256', ''] as $alg) {
            try {
                $validator->validate($this->sign([], ['alg' => $alg]));
                self::fail("alg $alg was accepted");
            } catch (IdTokenException $refused) {
                self::assertStringContainsString('algorithm', $refused->getMessage());
            }
        }
    }

    public function testAnHmacTokenMadeWithThePublicKeyAsSecretIsRefused(): void
    {
        $validator = $this->validator([self::jwks($this->publicJwk)]);
        $input = self::b64('{"alg":"HS256","kid":"mine"}') . '.' . self::b64(json_encode(['iss' => $this->fx['issuer'], 'sub' => 'u', 'aud' => 'client-123', 'exp' => $this->now + 60, 'iat' => $this->now], JSON_THROW_ON_ERROR));
        $token = $input . '.' . self::b64(hash_hmac('sha256', $input, $this->publicJwk['n'], true));
        $this->expectException(IdTokenException::class);
        $validator->validate($token);
    }

    public function testTheCallerCanNarrowTheAcceptedAlgorithms(): void
    {
        $validator = $this->validator([self::jwks($this->publicJwk)], ['algorithms' => ['ES256']]);
        $this->expectExceptionMessage('algorithm');
        $validator->validate($this->sign());
    }

    public function testAKeyOfAnotherTypeIsNotUsed(): void
    {
        $ec = $this->fx['jwks']['keys'][1];
        $validator = $this->validator([self::jwks(['kid' => 'mine'] + $ec)]);
        $this->expectException(IdTokenException::class);
        $validator->validate($this->sign());
    }

    public function testAKeyForEncryptionOrAnotherAlgorithmIsNotUsed(): void
    {
        foreach ([['use' => 'enc'], ['alg' => 'RS512']] as $restriction) {
            $validator = $this->validator([self::jwks($restriction + $this->publicJwk)]);
            try {
                $validator->validate($this->sign());
                self::fail('The key was used.');
            } catch (IdTokenException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAWeakRsaKeyIsNotTrusted(): void
    {
        $validator = $this->validator([self::jwks(...$this->fx['weakJwks']['keys'])]);
        $this->expectException(IdTokenException::class);
        $validator->validate($this->fx['tokens']['weak'], null, null);
    }

    public function testCriticalHeaderExtensionsAreRefused(): void
    {
        $validator = $this->validator([self::jwks($this->publicJwk)]);
        $this->expectExceptionMessage('extensions');
        $validator->validate($this->sign([], ['crit' => ['exp']]));
    }

    public function testKeysEmbeddedInTheTokenAreNeverUsed(): void
    {
        $validator = $this->validator([self::jwks(...$this->fx['jwks']['keys'])]);
        $this->expectException(IdTokenException::class);
        $validator->validate($this->sign([], ['jwk' => $this->publicJwk, 'kid' => 'rsa-1']));
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'two parts' => ['a.b'];
        yield 'four parts' => ['a.b.c.d'];
        yield 'not base64' => ['!!.!!.!!'];
        yield 'header not json' => [rtrim(strtr(base64_encode('nope'), '+/', '-_'), '=') . '.e30.AAAA'];
        yield 'claims a list' => ['eyJhbGciOiJSUzI1NiJ9.W10.AAAA'];
        yield 'oversize' => [str_repeat('a', 20000) . '.b.c'];
    }

    #[DataProvider('malformed')]
    public function testRefusesMalformedTokens(string $token): void
    {
        $validator = $this->validator([self::jwks($this->publicJwk)]);
        $this->expectException(IdTokenException::class);
        $validator->validate($token);
    }

    /** @return iterable<string, array{array<string, mixed>, string, 2?: ?string, 3?: ?string}> */
    public static function badClaims(): iterable
    {
        yield 'other issuer' => [['iss' => 'https://evil.example.com'], 'issued by'];
        yield 'issuer with a trailing slash' => [['iss' => 'https://login.example.com/tenant/'], 'issued by'];
        yield 'no issuer' => [['iss' => null], 'issued by'];
        yield 'no subject' => [['sub' => null], 'subject'];
        yield 'empty subject' => [['sub' => ''], 'subject'];
        yield 'long subject' => [['sub' => str_repeat('s', 256)], 'subject'];
        yield 'numeric subject' => [['sub' => 42], 'subject'];
        yield 'other audience' => [['aud' => 'another-client'], 'not for this client'];
        yield 'no audience' => [['aud' => null], 'not for this client'];
        yield 'audience list without us' => [['aud' => ['a', 'b']], 'not for this client'];
        yield 'several audiences without azp' => [['aud' => ['client-123', 'other']], 'authorized party'];
        yield 'other azp' => [['aud' => ['client-123', 'other'], 'azp' => 'other'], 'another client'];
        yield 'expired' => [['exp' => 1799999900], 'expired'];
        yield 'no exp' => [['exp' => null], 'exp'];
        yield 'exp as string' => [['exp' => '9999999999'], 'exp'];
        yield 'no iat' => [['iat' => null], 'iat'];
        yield 'issued in the future' => [['iat' => 1800000500], 'future'];
        yield 'not before' => [['nbf' => 1800000500], 'not valid yet'];
        yield 'nbf as string' => [['nbf' => 'soon'], 'not valid yet'];
    }

    /** @param array<string, mixed> $claims */
    #[DataProvider('badClaims')]
    public function testRefusesTokensWithBadClaims(array $claims, string $message): void
    {
        $validator = $this->validator([self::jwks($this->publicJwk)]);
        try {
            $validator->validate($this->sign($claims));
            self::fail('A token with bad claims was accepted.');
        } catch (IdTokenException $refused) {
            self::assertStringContainsString($message, $refused->getMessage());
        }
    }

    public function testToleratesTheLeewayAndNoMore(): void
    {
        $validator = $this->validator([self::jwks($this->publicJwk)]);
        self::assertSame('u1', $validator->validate($this->sign(['exp' => $this->now - 59, 'iat' => $this->now + 59, 'nbf' => $this->now + 59]))->subject());
        $this->expectExceptionMessage('expired');
        $validator->validate($this->sign(['exp' => $this->now - 60]));
    }

    public function testASingleAudienceAsAListAndAnAzpAreAccepted(): void
    {
        $validator = $this->validator([self::jwks($this->publicJwk)]);
        $token = $validator->validate($this->sign(['aud' => ['client-123', 'other'], 'azp' => 'client-123']));
        self::assertSame(['client-123', 'other'], $token->audience());
    }

    public function testTheNonceMustBeTheOneSent(): void
    {
        $validator = $this->validator([self::jwks($this->publicJwk)]);
        self::assertSame('u1', $validator->validate($this->sign(['nonce' => 'abc']), 'abc')->subject());
        foreach ([$this->sign(['nonce' => 'abd']), $this->sign(), $this->sign(['nonce' => 123])] as $token) {
            try {
                $validator->validate($token, 'abc');
                self::fail('A wrong nonce was accepted.');
            } catch (IdTokenException $refused) {
                self::assertStringContainsString('nonce', $refused->getMessage());
            }
        }
    }

    public function testTheAccessTokenHashIsCheckedWhenBothArePresent(): void
    {
        $validator = $this->validator([self::jwks(...$this->fx['jwks']['keys'])]);
        $token = $this->fx['tokens']['RS256'];
        self::assertSame('user-42', $validator->validate($token, null, null)->subject());
        $this->expectExceptionMessage('access token');
        $validator->validate($token, null, 'another-access-token');
    }

    public function testKeysAreFetchedOnceAndKeptAndRefetchedForAnUnknownKidAtMostEveryMinute(): void
    {
        $rotated = ['kid' => 'rotated'] + $this->publicJwk;
        $http = $this->http([self::jwks($this->fx['jwks']['keys'][1]), self::jwks($rotated)]);
        $validator = new IdTokenValidator(
            new OpenIdConfiguration($this->fx['issuer'], null, null, [], [], self::JWKS_URI),
            'client-123',
            new IdTokenOptions(http: $http, clock: fn(): int => $this->now),
        );
        $token = $this->sign([], ['kid' => 'rotated']);
        try {
            $validator->validate($token);
            self::fail('Unknown kid accepted.');
        } catch (IdTokenException) {
            self::assertSame(1, $http->calls);
        }
        $this->now += 30;
        try {
            $validator->validate($token);
            self::fail('Unknown kid accepted.');
        } catch (IdTokenException) {
            self::assertSame(1, $http->calls, 'Inside the cooldown the provider is not asked again.');
        }
        $this->now += 31;
        self::assertSame('u1', $validator->validate($this->sign(['exp' => $this->now + 600, 'iat' => $this->now], ['kid' => 'rotated']))->subject());
        self::assertSame(2, $http->calls);
        $validator->validate($this->sign(['exp' => $this->now + 600, 'iat' => $this->now], ['kid' => 'rotated']));
        self::assertSame(2, $http->calls, 'Known keys are cached.');
    }

    public function testATokenWithoutKidTriesTheKeysThatSuit(): void
    {
        $validator = $this->validator([self::jwks($this->fx['jwks']['keys'][1], $this->publicJwk)]);
        self::assertSame('u1', $validator->validate($this->sign([], ['kid' => null]))->subject());
    }

    public function testKeysAreFetchedAgainAfterTheirTimeToLive(): void
    {
        $http = $this->http([self::jwks($this->publicJwk), self::jwks($this->publicJwk)]);
        $validator = new IdTokenValidator(
            new OpenIdConfiguration($this->fx['issuer'], null, null, [], [], self::JWKS_URI),
            'client-123',
            new IdTokenOptions(http: $http, keysTtl: 100, clock: fn(): int => $this->now),
        );
        $validator->validate($this->sign());
        $this->now += 101;
        $validator->validate($this->sign(['exp' => $this->now + 600, 'iat' => $this->now]));
        self::assertSame(2, $http->calls);
    }

    /** @return iterable<string, array{ResponseInterface}> */
    public static function badKeyResponses(): iterable
    {
        yield 'http error' => [new Response(500)];
        yield 'not json' => [new Response(200, [], 'nope')];
        yield 'no keys member' => [new Response(200, [], '{}')];
        yield 'keys not a list' => [new Response(200, [], '{"keys":{"a":1}}')];
        yield 'oversize' => [new Response(200, [], str_repeat(' ', 1048577))];
    }

    #[DataProvider('badKeyResponses')]
    public function testRefusesAnUnusableKeySet(ResponseInterface $response): void
    {
        $validator = $this->validator([$response]);
        $this->expectException(OAuth2Exception::class);
        $validator->validate($this->sign());
    }

    public function testNeedsAJwksUriOverTls(): void
    {
        try {
            new IdTokenValidator(new OpenIdConfiguration($this->fx['issuer'], null, null), 'client-123');
            self::fail('No jwks_uri accepted.');
        } catch (OAuth2Exception) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        new IdTokenValidator(new OpenIdConfiguration($this->fx['issuer'], null, null, [], [], 'http://login.example.com/keys'), 'client-123');
    }

    public function testRejectsInvalidSettings(): void
    {
        foreach ([['algorithms' => []], ['algorithms' => ['none']], ['leeway' => -1], ['keysTtl' => -1]] as $options) {
            try {
                new IdTokenOptions(...$options);
                self::fail('Invalid options accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new IdTokenValidator(new OpenIdConfiguration($this->fx['issuer'], null, null, [], [], self::JWKS_URI), '');
    }

    public function testDebugOutputShowsNoClaims(): void
    {
        $validator = $this->validator([self::jwks($this->publicJwk)]);
        $token = $validator->validate($this->sign(['email' => 'secret@example.com']));
        self::assertStringNotContainsString('secret@example.com', print_r($token, true));
        self::assertStringContainsString('u1', print_r($token, true));
    }
}
