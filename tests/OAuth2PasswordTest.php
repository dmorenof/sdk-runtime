<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\Security\InMemoryTokenStore;
use Dmorenof\SdkRuntime\Security\OAuth2Client;
use Dmorenof\SdkRuntime\Security\OAuth2Endpoints;
use Dmorenof\SdkRuntime\Security\OAuth2Exception;
use Dmorenof\SdkRuntime\Security\OAuth2Options;
use Dmorenof\SdkRuntime\Security\OAuth2Password;
use Dmorenof\SdkRuntime\Security\ResourceOwner;
use Dmorenof\SdkRuntime\Security\SecurityApplier;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class OAuth2PasswordTest extends TestCase
{
    private const string TOKEN_URL = 'https://auth.example.com/oauth/token';

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

    private static function token(string $access, int $expiresIn = 3600, ?string $refresh = null): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(array_filter([
            'access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $expiresIn, 'refresh_token' => $refresh,
        ]), JSON_THROW_ON_ERROR));
    }

    private static function provider(ClientInterface $http, ?InMemoryTokenStore $store = null, string $user = 'ada', ?\Closure $clock = null, string $subject = ''): OAuth2Password
    {
        return new OAuth2Password(
            new OAuth2Endpoints(self::TOKEN_URL),
            new OAuth2Client('app', 's3cret'),
            new ResourceOwner($user, 'pa55'),
            new OAuth2Options(['read', 'write'], $http, $store, $subject, clock: $clock),
        );
    }

    /** @return array<string, string> */
    private static function form(RequestInterface $request): array
    {
        parse_str((string) $request->getBody(), $form);
        return array_map('strval', $form);
    }

    public function testTheGrantSendsTheUserCredentialsAndTheScopesWithTheClientAuthentication(): void
    {
        $http = $this->http([self::token('abc')]);
        self::assertSame('abc', self::provider($http)->accessToken());
        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::TOKEN_URL, (string) $request->getUri());
        self::assertSame(['grant_type' => 'password', 'username' => 'ada', 'password' => 'pa55', 'scope' => 'read write'], self::form($request));
        self::assertSame('Basic ' . base64_encode('app:s3cret'), $request->getHeaderLine('Authorization'));
    }

    public function testATokenThatHasNotExpiredIsReusedWithoutAnotherRequest(): void
    {
        $http = $this->http([self::token('abc')]);
        $provider = self::provider($http);
        self::assertSame('abc', $provider->accessToken());
        self::assertSame('abc', $provider->accessToken());
        self::assertCount(1, $http->requests);
    }

    public function testAnExpiredTokenIsRenewedWithItsRefreshToken(): void
    {
        $now = 1000;
        $http = $this->http([self::token('old', 60, 'r1'), self::token('new', 3600, 'r2')]);
        $provider = self::provider($http, clock: static function () use (&$now): int { return $now; });
        self::assertSame('old', $provider->accessToken());
        $now = 5000;
        self::assertSame('new', $provider->accessToken());
        self::assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'r1'], self::form($http->requests[1]));
    }

    public function testARejectedRefreshTokenLogsInAgainWithThePassword(): void
    {
        $now = 1000;
        $http = $this->http([self::token('old', 60, 'r1'), new Response(400, [], '{"error":"invalid_grant"}'), self::token('fresh')]);
        $provider = self::provider($http, clock: static function () use (&$now): int { return $now; });
        $provider->accessToken();
        $now = 5000;
        self::assertSame('fresh', $provider->accessToken());
        self::assertSame('password', self::form($http->requests[2])['grant_type']);
    }

    public function testAPasswordTheServerRefusesRaisesAnErrorThatNamesTheCodeAndNothingSecret(): void
    {
        $http = $this->http([new Response(400, [], '{"error":"invalid_grant","error_description":"bad pa55"}')]);
        try {
            self::provider($http)->accessToken();
            self::fail('A refused login was accepted.');
        } catch (OAuth2Exception $exception) {
            self::assertTrue($exception->isRejection());
            self::assertStringContainsString('invalid_grant', $exception->getMessage());
            self::assertStringNotContainsString('pa55', $exception->getMessage());
        }
    }

    public function testTwoUsersOfASharedStoreKeepTheirOwnTokens(): void
    {
        $store = new InMemoryTokenStore();
        $http = $this->http([self::token('for-ada'), self::token('for-grace')]);
        self::assertSame('for-ada', self::provider($http, $store, 'ada')->accessToken());
        self::assertSame('for-grace', self::provider($http, $store, 'grace')->accessToken());
        self::assertSame('for-ada', self::provider($http, $store, 'ada')->accessToken(), 'ada gets hers back from the store');
        self::assertCount(2, $http->requests);
    }

    public function testTheProviderWorksAsABearerCredential(): void
    {
        $schemes = ['OAuth' => ['type' => 'oauth2', 'scheme' => null, 'location' => null, 'parameterName' => null]];
        $http = $this->http([self::token('abc')]);
        $applied = SecurityApplier::apply(new SecurityCredentials(['OAuth' => self::provider($http)]), [['OAuth' => []]], $schemes);
        self::assertSame(['Authorization' => 'Bearer abc'], $applied->headers);
    }

    public function testNeitherTheDebugOutputNorAnEmptyOwnerExposesThePassword(): void
    {
        self::assertStringNotContainsString('pa55', print_r(self::provider($this->http([])), true));
        self::assertStringNotContainsString('pa55', print_r(new ResourceOwner('ada', 'pa55'), true));
        foreach ([['', 'x'], ['x', '']] as [$user, $password]) {
            try {
                new ResourceOwner($user, $password);
                self::fail('An empty credential was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
