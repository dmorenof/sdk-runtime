<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\Security\AuthorizationRequest;
use Dmorenof\SdkRuntime\Security\InMemoryTokenStore;
use Dmorenof\SdkRuntime\Security\OAuth2AuthorizationCode;
use Dmorenof\SdkRuntime\Security\OAuth2ClientCredentials;
use Dmorenof\SdkRuntime\Security\OAuth2Exception;
use Dmorenof\SdkRuntime\Security\Pkce;
use Dmorenof\SdkRuntime\Security\ReauthorizationRequiredException;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;
use Dmorenof\SdkRuntime\Tests\Fixtures\TestClient;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use Dmorenof\SdkRuntime\Transport\TransportInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class OAuth2AuthorizationCodeTest extends TestCase
{
    private const string AUTHORIZE = 'https://auth.example.com/authorize?tenant=t1';
    private const string TOKEN = 'https://auth.example.com/token';
    private const string REDIRECT = 'https://app.example.com/callback';

    /** @param list<ResponseInterface> $responses */
    private function http(array $responses): object
    {
        return new class ($responses) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];

            /** @param list<ResponseInterface> $responses */
            public function __construct(private array $responses) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                return array_shift($this->responses) ?? throw new \LogicException('No scripted response left.');
            }
        };
    }

    private static function token(string $access, ?int $expiresIn = 3600, ?string $refresh = null): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(array_filter([
            'access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $expiresIn, 'refresh_token' => $refresh,
        ], static fn ($v) => $v !== null), JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<ResponseInterface> $responses
     * @param array<string,mixed> $options Named constructor overrides.
     * @return array{OAuth2AuthorizationCode, object}
     */
    private function flow(array $responses = [], array $options = []): array
    {
        $http = $this->http($responses);
        $options += ['clientSecret' => 'the-secret', 'scopes' => ['read', 'write']];
        return [new OAuth2AuthorizationCode(self::AUTHORIZE, self::TOKEN, 'client-1', self::REDIRECT, $http, ...$options), $http];
    }

    /** @return array<string,string> */
    private static function form(RequestInterface $request): array
    {
        parse_str((string) $request->getBody(), $form);
        return $form;
    }

    public function testPkceMatchesTheRfc7636Example(): void
    {
        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
    }

    public function testVerifiersAreRandomAndWithinTheAllowedShape(): void
    {
        $a = Pkce::verifier();
        $b = Pkce::verifier();

        self::assertNotSame($a, $b);
        self::assertTrue(Pkce::isValidVerifier($a));
        self::assertSame(86, strlen($a));
        self::assertSame(43, strlen(Pkce::verifier(32)));
        self::assertSame(128, strlen(Pkce::verifier(96)));
        self::assertFalse(Pkce::isValidVerifier(str_repeat('a', 42)));
        self::assertFalse(Pkce::isValidVerifier(str_repeat('a', 129)));
        self::assertFalse(Pkce::isValidVerifier(str_repeat('a', 43) . '+'));

        $this->expectException(\InvalidArgumentException::class);
        Pkce::verifier(31);
    }

    public function testStartBuildsTheAuthorizationUrl(): void
    {
        [$flow] = $this->flow(options: ['clock' => static fn (): int => 1000]);

        $request = $flow->start(['audience' => 'https://api.example.com', 'prompt' => 'consent']);

        self::assertStringStartsWith('https://auth.example.com/authorize?tenant=t1&response_type=code', $request->url);
        parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
        self::assertSame('t1', $query['tenant']);
        self::assertSame('code', $query['response_type']);
        self::assertSame('client-1', $query['client_id']);
        self::assertSame(self::REDIRECT, $query['redirect_uri']);
        self::assertSame('read write', $query['scope']);
        self::assertSame($request->state, $query['state']);
        self::assertSame(Pkce::challenge($request->codeVerifier), $query['code_challenge']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('https://api.example.com', $query['audience']);
        self::assertSame('consent', $query['prompt']);
        self::assertStringNotContainsString($request->codeVerifier, $request->url, 'the verifier never travels in the URL');
        self::assertSame(1600, $request->expiresAt);
    }

    public function testStartsAreIndependentAndScopeIsOptional(): void
    {
        [$flow] = $this->flow(options: ['scopes' => []]);

        $a = $flow->start();
        $b = $flow->start();

        self::assertNotSame($a->state, $b->state);
        self::assertNotSame($a->codeVerifier, $b->codeVerifier);
        self::assertStringNotContainsString('scope=', $a->url);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function badExtraParameters(): iterable
    {
        yield 'state' => [['state' => 'fixed']];
        yield 'challenge' => [['code_challenge' => 'x']];
        yield 'redirect' => [['redirect_uri' => 'https://evil.test']];
        yield 'scope' => [['scope' => 'admin']];
        yield 'non string' => [['max_age' => 5]];
        yield 'numeric name' => [['x']];
    }

    #[DataProvider('badExtraParameters')]
    public function testExtraParametersCannotReplaceProtocolOnes(array $extra): void
    {
        [$flow] = $this->flow();

        $this->expectException(\InvalidArgumentException::class);
        $flow->start($extra);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function endpoints(): iterable
    {
        yield 'https redirect' => ['https://auth.example.com/authorize', 'https://app.example.com/cb', true];
        yield 'loopback http redirect' => ['https://auth.example.com/authorize', 'http://127.0.0.1:8080/cb', true];
        yield 'localhost http redirect' => ['https://auth.example.com/authorize', 'http://localhost/cb', true];
        yield 'private-use scheme' => ['https://auth.example.com/authorize', 'com.example.app:/oauth2redirect', true];
        yield 'loopback authorization server' => ['http://localhost:9000/authorize', 'https://app.example.com/cb', true];
        yield 'cleartext authorization server' => ['http://auth.example.com/authorize', 'https://app.example.com/cb', false];
        yield 'cleartext redirect' => ['https://auth.example.com/authorize', 'http://app.example.com/cb', false];
        yield 'redirect fragment' => ['https://auth.example.com/authorize', 'https://app.example.com/cb#x', false];
        yield 'redirect credentials' => ['https://auth.example.com/authorize', 'https://u:p@app.example.com/cb', false];
        yield 'scheme without a dot' => ['https://auth.example.com/authorize', 'myapp:/cb', false];
        yield 'script scheme' => ['https://auth.example.com/authorize', 'javascript:alert(1)', false];
        yield 'relative redirect' => ['https://auth.example.com/authorize', '/cb', false];
        yield 'authorization fragment' => ['https://auth.example.com/authorize#x', 'https://app.example.com/cb', false];
    }

    #[DataProvider('endpoints')]
    public function testEndpointAndRedirectValidation(string $authorize, string $redirect, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(\InvalidArgumentException::class);
        }
        $flow = new OAuth2AuthorizationCode($authorize, self::TOKEN, 'client-1', $redirect, $this->http([]));
        self::assertInstanceOf(OAuth2AuthorizationCode::class, $flow);
    }

    public function testTokenEndpointRequiresTlsExceptOnLoopback(): void
    {
        new OAuth2ClientCredentials('http://localhost:9000/token', 'id', 'secret', $this->http([]));

        $this->expectException(\InvalidArgumentException::class);
        new OAuth2ClientCredentials('http://auth.example.com/token', 'id', 'secret', $this->http([]));
    }

    public function testCompleteExchangesTheCodeWithTheVerifierAndStoresTheToken(): void
    {
        [$flow, $http] = $this->flow([self::token('a1', 3600, 'r1')]);
        $request = $flow->start();

        $token = $flow->complete($request, ['code' => 'the-code', 'state' => $request->state]);

        self::assertSame('a1', $token->accessToken);
        self::assertSame('r1', $token->refreshToken);
        self::assertCount(1, $http->requests);
        $sent = $http->requests[0];
        self::assertSame(self::TOKEN, (string) $sent->getUri());
        self::assertSame('Basic ' . base64_encode('client-1:the-secret'), $sent->getHeaderLine('Authorization'));
        self::assertSame([
            'grant_type' => 'authorization_code',
            'code' => 'the-code',
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $request->codeVerifier,
        ], self::form($sent));
        self::assertSame('a1', $flow->accessToken());
        self::assertCount(1, $http->requests, 'the stored token is reused');
        self::assertTrue($flow->isAuthorized());
    }

    public function testPublicClientSendsItsIdInsteadOfASecret(): void
    {
        [$flow, $http] = $this->flow([self::token('a1')], ['clientSecret' => null]);
        $request = $flow->start();

        $flow->complete($request, ['code' => 'c', 'state' => $request->state]);

        self::assertSame('', $http->requests[0]->getHeaderLine('Authorization'));
        self::assertSame('client-1', self::form($http->requests[0])['client_id']);
        self::assertArrayNotHasKey('client_secret', self::form($http->requests[0]));
    }

    public function testCredentialsCanTravelInTheBody(): void
    {
        [$flow, $http] = $this->flow([self::token('a1')], ['credentialsInBody' => true]);
        $request = $flow->start();

        $flow->complete($request, ['code' => 'c', 'state' => $request->state]);

        self::assertSame('', $http->requests[0]->getHeaderLine('Authorization'));
        self::assertSame(['client-1', 'the-secret'], [self::form($http->requests[0])['client_id'], self::form($http->requests[0])['client_secret']]);
    }

    /** @return iterable<string, array<string,mixed>> */
    public static function badCallbacks(): iterable
    {
        yield 'missing state' => [['code' => 'c'], 'state'];
        yield 'wrong state' => [['code' => 'c', 'state' => 'forged'], 'state'];
        yield 'array state' => [['code' => 'c', 'state' => ['x']], 'state'];
        yield 'missing code' => [['state' => '{state}'], 'no authorization code'];
        yield 'empty code' => [['state' => '{state}', 'code' => ''], 'no authorization code'];
        yield 'access denied' => [['state' => '{state}', 'error' => 'access_denied', 'error_description' => 'user said no'], 'access_denied'];
        yield 'hostile error' => [['state' => '{state}', 'error' => "bad\nvalue"], 'unknown_error'];
    }

    /** @param array<string,mixed> $callback */
    #[DataProvider('badCallbacks')]
    public function testInvalidCallbacksFailBeforeAnyNetworkCall(array $callback, string $expected): void
    {
        [$flow, $http] = $this->flow();
        $request = $flow->start();
        $callback = array_map(static fn ($v) => $v === '{state}' ? $request->state : $v, $callback);

        try {
            $flow->complete($request, $callback);
            self::fail('Expected OAuth2Exception.');
        } catch (OAuth2Exception $e) {
            self::assertStringContainsString($expected, $e->getMessage());
            self::assertStringNotContainsString('user said no', $e->getMessage(), 'server-provided text is never echoed');
            self::assertSame([], $http->requests);
        }
    }

    public function testIssuerIsCheckedWhenConfigured(): void
    {
        [$flow, $http] = $this->flow([self::token('a1')], ['issuer' => 'https://auth.example.com']);
        $request = $flow->start();

        foreach ([[], ['iss' => 'https://evil.example.com']] as $extra) {
            try {
                $flow->complete($request, ['code' => 'c', 'state' => $request->state] + $extra);
                self::fail('Expected OAuth2Exception.');
            } catch (OAuth2Exception) {
                self::assertSame([], $http->requests);
            }
        }
        $flow->complete($request, ['code' => 'c', 'state' => $request->state, 'iss' => 'https://auth.example.com']);
        self::assertCount(1, $http->requests);
    }

    public function testExpiredRequestsAreRejected(): void
    {
        $now = 1000;
        [$flow, $http] = $this->flow([], ['clock' => function () use (&$now): int {
            return $now;
        }]);
        $request = $flow->start();
        $now = 1000 + 600;

        try {
            $flow->complete($request, ['code' => 'c', 'state' => $request->state]);
            self::fail('Expected OAuth2Exception.');
        } catch (OAuth2Exception $e) {
            self::assertStringContainsString('expired', $e->getMessage());
            self::assertSame([], $http->requests);
        }
    }

    public function testTokenEndpointFailuresSurfaceAsOAuth2ExceptionsWithoutSecrets(): void
    {
        [$flow] = $this->flow([new Response(400, [], '{"error":"invalid_grant","error_description":"code the-code reused"}')]);
        $request = $flow->start();

        try {
            $flow->complete($request, ['code' => 'the-code', 'state' => $request->state]);
            self::fail('Expected OAuth2Exception.');
        } catch (OAuth2Exception $e) {
            self::assertSame('OAuth2 token request failed with HTTP 400: invalid_grant.', $e->getMessage());
            self::assertFalse($flow->isAuthorized());
        }
    }

    public function testRenewsWithTheRefreshTokenAndFollowsRotation(): void
    {
        $now = 0;
        [$flow, $http] = $this->flow(
            [self::token('a1', 100, 'r1'), self::token('a2', 100, 'r2'), self::token('a3', 100)],
            ['leeway' => 0, 'clock' => function () use (&$now): int {
                return $now;
            }],
        );
        $request = $flow->start();
        $flow->complete($request, ['code' => 'c', 'state' => $request->state]);

        $now = 200;
        self::assertSame('a2', $flow->accessToken());
        $now = 400;
        self::assertSame('a3', $flow->accessToken());

        self::assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'r1'], self::form($http->requests[1]));
        self::assertSame('r2', self::form($http->requests[2])['refresh_token'], 'a rotated refresh token replaces the old one');
    }

    public function testRejectedRefreshTokenRequiresReauthorization(): void
    {
        $now = 0;
        [$flow] = $this->flow(
            [self::token('a1', 10, 'r1'), new Response(400, [], '{"error":"invalid_grant"}')],
            ['leeway' => 0, 'clock' => function () use (&$now): int {
                return $now;
            }],
        );
        $request = $flow->start();
        $flow->complete($request, ['code' => 'c', 'state' => $request->state]);
        $now = 100;

        try {
            $flow->accessToken();
            self::fail('Expected ReauthorizationRequiredException.');
        } catch (ReauthorizationRequiredException) {
            self::assertFalse($flow->isAuthorized());
        }
    }

    public function testNoTokenOrNonRenewableTokenRequiresReauthorization(): void
    {
        [$flow] = $this->flow();
        try {
            $flow->accessToken();
            self::fail('Expected ReauthorizationRequiredException.');
        } catch (ReauthorizationRequiredException $e) {
            self::assertInstanceOf(OAuth2Exception::class, $e);
        }

        $now = 0;
        [$flow] = $this->flow([self::token('a1', 10)], ['leeway' => 0, 'clock' => function () use (&$now): int {
            return $now;
        }]);
        $request = $flow->start();
        $flow->complete($request, ['code' => 'c', 'state' => $request->state]);
        $now = 100;
        $this->expectException(ReauthorizationRequiredException::class);
        $flow->accessToken();
    }

    public function testInvalidateKeepsTheRefreshTokenOtherwiseForgets(): void
    {
        [$flow, $http] = $this->flow([self::token('a1', 3600, 'r1'), self::token('a2', 3600, 'r1')]);
        $request = $flow->start();
        $flow->complete($request, ['code' => 'c', 'state' => $request->state]);

        $flow->invalidate();
        self::assertSame('a2', $flow->accessToken());
        self::assertSame('refresh_token', self::form($http->requests[1])['grant_type']);

        [$bare] = $this->flow([self::token('b1', 3600)]);
        $request = $bare->start();
        $bare->complete($request, ['code' => 'c', 'state' => $request->state]);
        $bare->invalidate();
        self::assertFalse($bare->isAuthorized());
    }

    public function testSubjectsKeepUsersApartInASharedStore(): void
    {
        $store = new InMemoryTokenStore();
        [$ada] = $this->flow([self::token('ada-token')], ['store' => $store, 'subject' => 'ada']);
        [$lin] = $this->flow([], ['store' => $store, 'subject' => 'lin']);

        $request = $ada->start();
        $ada->complete($request, ['code' => 'c', 'state' => $request->state]);

        self::assertTrue($ada->isAuthorized());
        self::assertFalse($lin->isAuthorized());
        $ada->forget();
        self::assertFalse($ada->isAuthorized());
    }

    public function testDebugOutputHidesSecrets(): void
    {
        [$flow] = $this->flow();
        $request = $flow->start();

        $dump = print_r($flow, true) . print_r($request, true);

        self::assertStringNotContainsString('the-secret', $dump);
        self::assertStringNotContainsString($request->codeVerifier, $dump);
        self::assertStringNotContainsString($request->state, $dump);
    }

    public function testRestoredRequestObjectsWork(): void
    {
        [$flow, $http] = $this->flow([self::token('a1')]);
        $started = $flow->start();
        $restored = new AuthorizationRequest('', $started->state, $started->codeVerifier, $started->expiresAt); // e.g. from the session

        $flow->complete($restored, ['code' => 'c', 'state' => $started->state]);

        self::assertSame($started->codeVerifier, self::form($http->requests[0])['code_verifier']);
    }

    public function testApiClientRenewsAndReplaysAfter401(): void
    {
        [$flow] = $this->flow([self::token('old', 3600, 'r1'), self::token('new', 3600, 'r1')]);
        $request = $flow->start();
        $flow->complete($request, ['code' => 'c', 'state' => $request->state]);
        $transport = new class implements TransportInterface {
            /** @var list<string> */
            public array $authorization = [];

            public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
            {
                $this->authorization[] = $headers['Authorization'];
                return $headers['Authorization'] === 'Bearer new' ? new HttpResponse(200, '{"id":1,"name":"Ada"}') : new HttpResponse(401, '{}');
            }
        };
        $client = TestClient::connect('https://api.example.com', securityCredentials: new SecurityCredentials(['Bearer' => $flow]), transport: $transport);

        self::assertSame('Ada', $client->getUser(1)->name);
        self::assertSame(['Bearer old', 'Bearer new'], $transport->authorization);
    }
}
