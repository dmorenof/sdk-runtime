<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\ApiException;
use Dmorenof\SdkRuntime\Configuration;
use Dmorenof\SdkRuntime\Security\InsufficientScopeException;
use Dmorenof\SdkRuntime\Security\OAuth2Client;
use Dmorenof\SdkRuntime\Security\OAuth2ClientCredentials;
use Dmorenof\SdkRuntime\Security\OAuth2Endpoints;
use Dmorenof\SdkRuntime\Security\OAuth2Implicit;
use Dmorenof\SdkRuntime\Security\OAuth2Options;
use Dmorenof\SdkRuntime\Security\OAuth2Token;
use Dmorenof\SdkRuntime\Security\ScopeAwareTokenProvider;
use Dmorenof\SdkRuntime\Security\SecurityApplier;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;
use Dmorenof\SdkRuntime\Security\TokenProviderInterface;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ScopedClient extends ApiClient
{
    protected const array SECURITY_SCHEMES = [
        'OAuth' => ['type' => 'oauth2', 'scheme' => null, 'location' => null, 'parameterName' => null],
        'Other' => ['type' => 'oauth2', 'scheme' => null, 'location' => null, 'parameterName' => null],
    ];

    /** @param list<array<string, list<string>>> $security */
    public function call(array $security): mixed
    {
        return $this->executeArguments(['method' => 'GET', 'path' => '/things', 'success' => [204], 'security' => $security]);
    }
}

final class ScopeCheckTest extends TestCase
{
    private const array SCHEMES = [
        'OAuth' => ['type' => 'oauth2', 'scheme' => null, 'location' => null, 'parameterName' => null],
        'Other' => ['type' => 'oauth2', 'scheme' => null, 'location' => null, 'parameterName' => null],
    ];

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

    /** @param list<string>|null $scopes */
    private static function provider(?array $scopes, string $token = 'abc'): ScopeAwareTokenProvider
    {
        return new class ($scopes, $token) implements ScopeAwareTokenProvider {
            /** @param list<string>|null $scopes */
            public function __construct(private ?array $scopes, private string $token) {}

            public function accessToken(): string
            {
                return $this->token;
            }

            public function grantedScopes(): ?array
            {
                return $this->scopes;
            }

            public function invalidate(): void {}
        };
    }

    /** @param list<array<string, list<string>>> $requirements */
    private static function apply(array $credentials, array $requirements): \Dmorenof\SdkRuntime\Security\AppliedSecurity
    {
        return SecurityApplier::apply(new SecurityCredentials($credentials), $requirements, self::SCHEMES);
    }

    public function testATokenWithTheRequiredScopesPasses(): void
    {
        $applied = self::apply(['OAuth' => self::provider(['read', 'write', 'admin'])], [['OAuth' => ['read', 'write']]]);
        self::assertSame(['Authorization' => 'Bearer abc'], $applied->headers);
    }

    public function testATokenThatLacksAScopeFailsBeforeAnythingIsSentAndNamesTheMissingOnes(): void
    {
        try {
            self::apply(['OAuth' => self::provider(['read'])], [['OAuth' => ['read', 'write', 'admin']]]);
            self::fail('A token without the scopes was accepted.');
        } catch (InsufficientScopeException $exception) {
            self::assertSame('OAuth', $exception->scheme);
            self::assertSame(['write', 'admin'], $exception->missing);
            self::assertSame(['read'], $exception->granted);
            self::assertStringContainsString('write admin', $exception->getMessage());
            self::assertStringNotContainsString('abc', $exception->getMessage());
        }
    }

    public function testWhenTheScopesAreNotKnownNothingIsCheckedAndAnOperationWithoutScopesAlwaysPasses(): void
    {
        self::assertSame(['Authorization' => 'Bearer abc'], self::apply(['OAuth' => self::provider(null)], [['OAuth' => ['write']]])->headers);
        self::assertSame(['Authorization' => 'Bearer abc'], self::apply(['OAuth' => self::provider(['read'])], [['OAuth' => []]])->headers);
        self::assertSame(['Authorization' => 'Bearer abc'], self::apply(['OAuth' => 'abc'], [['OAuth' => ['write']]])->headers, 'a plain token string');
        $plain = new class implements TokenProviderInterface {
            public function accessToken(): string
            {
                return 'abc';
            }

            public function invalidate(): void {}
        };
        self::assertSame(['Authorization' => 'Bearer abc'], self::apply(['OAuth' => $plain], [['OAuth' => ['write']]])->headers, 'a provider that does not know its scopes');
    }

    public function testAnotherAlternativeThatNeedsLessIsUsedBeforeGivingUp(): void
    {
        $credentials = ['OAuth' => self::provider(['read'], 'read-token'), 'Other' => self::provider(['write'], 'write-token')];
        self::assertSame(['Authorization' => 'Bearer write-token'], self::apply($credentials, [['OAuth' => ['write']], ['Other' => ['write']]])->headers);

        $this->expectException(InsufficientScopeException::class);
        self::apply($credentials, [['OAuth' => ['admin']], ['Other' => ['admin']]]);
    }

    public function testAResponseThatNamesItsScopeGrantsThemAndOneThatDoesNotGrantsWhatWasRequested(): void
    {
        $http = $this->http([
            new Response(200, [], '{"access_token":"a","token_type":"Bearer","scope":"read  write"}'),
            new Response(200, [], '{"access_token":"b","token_type":"Bearer"}'),
            new Response(200, [], '{"access_token":"c","token_type":"Bearer"}'),
        ]);
        $make = static fn (array $scopes): OAuth2ClientCredentials => new OAuth2ClientCredentials(new OAuth2Endpoints('https://auth.example.com/token'), new OAuth2Client('app', 's'), new OAuth2Options($scopes, $http));
        $named = $make(['read']);
        $named->accessToken();
        self::assertSame(['read', 'write'], $named->grantedScopes(), 'the server named them');
        $silent = $make(['read', 'write']);
        $silent->accessToken();
        self::assertSame(['read', 'write'], $silent->grantedScopes(), 'the server said nothing: what was requested');
        $none = $make([]);
        $none->accessToken();
        self::assertNull($none->grantedScopes(), 'nothing requested and nothing said: not known');
    }

    public function testBeforeAnyTokenTheScopesAreNotKnown(): void
    {
        $provider = new OAuth2ClientCredentials(new OAuth2Endpoints('https://auth.example.com/token'), new OAuth2Client('app', 's'), new OAuth2Options(['read'], $this->http([])));
        self::assertNull($provider->grantedScopes());
    }

    public function testRenewingATokenKeepsItsScopesWhenTheResponseNamesNone(): void
    {
        $now = 1000;
        $http = $this->http([
            new Response(200, [], '{"access_token":"a","token_type":"Bearer","expires_in":60,"refresh_token":"r","scope":"read write"}'),
            new Response(200, [], '{"access_token":"b","token_type":"Bearer","expires_in":3600}'),
        ]);
        $provider = new OAuth2ClientCredentials(new OAuth2Endpoints('https://auth.example.com/token'), new OAuth2Client('app', 's'), new OAuth2Options(['read'], $http, clock: static function () use (&$now): int { return $now; }));
        $provider->accessToken();
        $now = 5000;
        self::assertSame('b', $provider->accessToken());
        self::assertSame(['read', 'write'], $provider->grantedScopes());
    }

    public function testAnImplicitTokenHasTheScopesOfTheFragmentOrTheRequestedOnes(): void
    {
        $make = static fn (): OAuth2Implicit => new OAuth2Implicit('https://auth.example.com/authorize', new OAuth2Client('app', redirectUri: 'https://app.example.com/cb'), new OAuth2Options(['read']));
        foreach (['&scope=read+write' => ['read', 'write'], '' => ['read']] as $tail => $expected) {
            $flow = $make();
            $request = $flow->start();
            $flow->complete($request, 'https://app.example.com/cb#access_token=abc&state=' . rawurlencode($request->state) . str_replace('+', '%20', $tail));
            self::assertSame($expected, $flow->grantedScopes());
        }
    }

    public function testATokenKeepsItsScopesAndDoesNotPrintThemWithItsSecret(): void
    {
        $token = new OAuth2Token('secret-token', 100, null, ['read']);
        self::assertSame(['read'], $token->scopes);
        self::assertStringNotContainsString('secret-token', print_r($token, true));
        self::assertNull((new OAuth2Token('t'))->scopes);
    }

    public function testACallThatNeedsAScopeTheTokenLacksSendsNothingToTheApi(): void
    {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $config = new Configuration();
        $config->baseUrl = 'https://api.example.test';
        $config->transport = $transport;
        $config->securityCredentials = new SecurityCredentials(['OAuth' => self::provider(['read'])]);
        $client = ScopedClient::connect($config);
        $client->call([['OAuth' => ['read']]]);
        self::assertCount(1, $transport->sent);

        $this->expectException(InsufficientScopeException::class);
        try {
            $client->call([['OAuth' => ['write']]]);
        } finally {
            self::assertCount(1, $transport->sent, 'the refused call never reached the API');
        }
    }

    /** @return array<string, array{array<string, string>, int, list<string>, bool}> */
    public static function answers(): array
    {
        return [
            'a quoted scope list' => [['WWW-Authenticate' => 'Bearer realm="api", error="insufficient_scope", scope="read write"'], 403, ['read', 'write'], true],
            'an unquoted error' => [['WWW-Authenticate' => 'Bearer error=insufficient_scope, scope="admin"'], 403, ['admin'], true],
            'the error without a scope' => [['WWW-Authenticate' => 'Bearer error="insufficient_scope"'], 403, [], true],
            'another error' => [['WWW-Authenticate' => 'Bearer error="invalid_token", scope="read"'], 403, [], false],
            'a challenge that is not a Bearer error' => [['WWW-Authenticate' => 'Basic realm="x"'], 403, [], false],
            'no challenge' => [[], 403, [], false],
            'another status' => [['WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="read"'], 401, [], false],
            'a header in any case' => [['www-authenticate' => 'Bearer error="insufficient_scope", scope="read"'], 403, ['read'], true],
        ];
    }

    /**
     * @param array<string, string> $headers
     * @param list<string> $scopes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('answers')]
    public function testTheApiNamingTheMissingScopeIsRecognized(array $headers, int $status, array $scopes, bool $insufficient): void
    {
        $exception = new ApiException(new HttpResponse($status, '', $headers), 'GET /things');
        self::assertSame($scopes, $exception->missingScopes());
        self::assertSame($insufficient, $exception->isInsufficientScope());
    }
}
