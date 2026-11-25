<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\Security\BasicAuthCredentials;
use Dmorenof\SdkRuntime\Security\OAuth2AuthorizationCode;
use Dmorenof\SdkRuntime\Security\OAuth2Client;
use Dmorenof\SdkRuntime\Security\OAuth2ClientCredentials;
use Dmorenof\SdkRuntime\Security\OAuth2Endpoints;
use Dmorenof\SdkRuntime\Security\OAuth2Exception;
use Dmorenof\SdkRuntime\Security\OAuth2Options;
use Dmorenof\SdkRuntime\Security\OAuth2Password;
use Dmorenof\SdkRuntime\Security\Pkce;
use Dmorenof\SdkRuntime\Security\ResourceOwner;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;
use Dmorenof\SdkRuntime\Tests\Fixtures\TestClient;
use Dmorenof\SdkRuntime\Tests\Support\Cfg;
use Dmorenof\SdkRuntime\Tests\Support\ScriptedServer;
use PHPUnit\Framework\TestCase;

/**
 * Authentication on a real socket: the credentials an SDK is given reach a scripted server on 127.0.0.1 as the specification says,
 * and the OAuth2 flows talk to a real token endpoint with the default Guzzle client. The other security tests use a fake client.
 */
final class RealSecurityTest extends TestCase
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

    /** @param list<array<string, mixed>> $script */
    private function server(array $script = []): ScriptedServer
    {
        return $this->servers[] = ScriptedServer::start($script);
    }

    /** @return array<string, mixed> */
    private static function user(): array
    {
        return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"id":1,"name":"Grace"}'];
    }

    /** @return array<string, mixed> */
    private static function token(string $access, int $expiresIn = 3600, ?string $refresh = null): array
    {
        return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(array_filter(['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $expiresIn, 'refresh_token' => $refresh]), JSON_THROW_ON_ERROR)];
    }

    /** @return array<string, list<string>> */
    private static function form(string $body): array
    {
        parse_str($body, $form);
        return array_map(static fn (mixed $value): array => [(string) $value], $form);
    }

    public function testABearerTokenIsSentInTheAuthorizationHeader(): void
    {
        $api = $this->server([self::user()]);
        $client = TestClient::connect(Cfg::make($api->url(), securityCredentials: new SecurityCredentials(['Bearer' => 'tok-123'])));

        self::assertSame('Grace', $client->getUser(1)->name);

        self::assertSame(['Bearer tok-123'], $api->requests()[0]['headers']['authorization']);
        self::assertSame('GET /users/1', $api->requests()[0]['method'] . ' ' . $api->requests()[0]['target']);
    }

    public function testBasicAndApiKeysReachTheServerWhereTheSchemeSays(): void
    {
        $api = $this->server([['status' => 204], ['status' => 204], ['status' => 204], ['status' => 204]]);
        $client = new class (Cfg::make($api->url(), securityCredentials: new SecurityCredentials([
            'Basic' => new BasicAuthCredentials('ada', 'p:ss word'),
            'KeyHeader' => 'k-header',
            'KeyQuery' => 'k query&1',
            'KeyCookie' => 'k-cookie',
        ]))) extends ApiClient {
            protected const array SECURITY_SCHEMES = [
                'Basic' => ['type' => 'http', 'scheme' => 'basic', 'location' => null, 'parameterName' => null],
                'KeyHeader' => ['type' => 'apiKey', 'scheme' => null, 'location' => 'header', 'parameterName' => 'X-Api-Key'],
                'KeyQuery' => ['type' => 'apiKey', 'scheme' => null, 'location' => 'query', 'parameterName' => 'api_key'],
                'KeyCookie' => ['type' => 'apiKey', 'scheme' => null, 'location' => 'cookie', 'parameterName' => 'session'],
            ];

            public function call(string $scheme): void
            {
                $this->executeArguments(['method' => 'GET', 'path' => '/secure', 'success' => [204], 'security' => [[$scheme => []]]]);
            }
        };

        foreach (['Basic', 'KeyHeader', 'KeyQuery', 'KeyCookie'] as $scheme) {
            $client->call($scheme);
        }

        [$basic, $header, $query, $cookie] = $api->requests();
        self::assertSame(['Basic ' . base64_encode('ada:p:ss word')], $basic['headers']['authorization']);
        self::assertSame(['k-header'], $header['headers']['x-api-key']);
        self::assertSame('/secure?api_key=k%20query%261', $query['target']);
        self::assertSame(['session=k-cookie'], $cookie['headers']['cookie']);
        foreach ([$header, $query, $cookie] as $request) {
            self::assertArrayNotHasKey('authorization', $request['headers']);
        }
    }

    public function testClientCredentialsGetATokenOnceAndUseItOnTheApi(): void
    {
        $auth = $this->server([self::token('t1')]);
        $api = $this->server([self::user(), self::user()]);
        $oauth = new OAuth2ClientCredentials(new OAuth2Endpoints($auth->url('/oauth/token')), new OAuth2Client('id:1', 'se cret'), new OAuth2Options(['read', 'write']));
        $client = TestClient::connect(Cfg::make($api->url(), securityCredentials: new SecurityCredentials(['Bearer' => $oauth])));

        $client->getUser(1);
        $client->getUser(2);

        $tokenRequests = $auth->requests();
        self::assertCount(1, $tokenRequests);
        self::assertSame('POST /oauth/token', $tokenRequests[0]['method'] . ' ' . $tokenRequests[0]['target']);
        self::assertSame(['Basic ' . base64_encode('id%3A1:se+cret')], $tokenRequests[0]['headers']['authorization']);
        self::assertSame(['application/x-www-form-urlencoded'], $tokenRequests[0]['headers']['content-type']);
        self::assertSame(['grant_type' => ['client_credentials'], 'scope' => ['read write']], self::form($tokenRequests[0]['body']));
        foreach ($api->requests() as $request) {
            self::assertSame(['Bearer t1'], $request['headers']['authorization']);
        }
    }

    public function testAnExpiredTokenIsRenewedWithItsRefreshToken(): void
    {
        $now = 1000;
        $auth = $this->server([self::token('t1', 100, 'r1'), self::token('t2', 100)]);
        $api = $this->server([self::user(), self::user()]);
        $oauth = new OAuth2ClientCredentials(
            new OAuth2Endpoints($auth->url('/token')),
            new OAuth2Client('id', 'secret'),
            new OAuth2Options(leeway: 0, clock: static function () use (&$now): int {
                return $now;
            }),
        );
        $client = TestClient::connect(Cfg::make($api->url(), securityCredentials: new SecurityCredentials(['Bearer' => $oauth])));

        $client->getUser(1);
        $now = 1200;
        $client->getUser(1);

        $renewal = $auth->requests()[1];
        self::assertSame(['grant_type' => ['refresh_token'], 'refresh_token' => ['r1']], self::form($renewal['body']));
        self::assertSame(['Bearer t1'], $api->requests()[0]['headers']['authorization']);
        self::assertSame(['Bearer t2'], $api->requests()[1]['headers']['authorization']);
    }

    public function testThePasswordGrantSendsTheOwnerCredentialsToTheTokenEndpoint(): void
    {
        $auth = $this->server([self::token('t1')]);
        $oauth = new OAuth2Password(new OAuth2Endpoints($auth->url('/token')), new OAuth2Client('id', 'secret'), new ResourceOwner('ada', 'p@ss&word'), new OAuth2Options(['read']));

        self::assertSame('t1', $oauth->accessToken());

        $request = $auth->requests()[0];
        self::assertSame(['grant_type' => ['password'], 'username' => ['ada'], 'password' => ['p@ss&word'], 'scope' => ['read']], self::form($request['body']));
        self::assertSame(['Basic ' . base64_encode('id:secret')], $request['headers']['authorization']);
    }

    public function testTheAuthorizationCodeFlowExchangesTheCodeWithItsPkceVerifier(): void
    {
        $auth = $this->server([self::token('t1', 3600, 'r1')]);
        $oauth = new OAuth2AuthorizationCode(
            new OAuth2Endpoints($auth->url('/token'), 'https://login.example.com/authorize'),
            new OAuth2Client('id', 'secret', 'https://app.example.com/callback'),
            new OAuth2Options(['read']),
        );

        $request = $oauth->start();
        parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
        $token = $oauth->complete($request, ['code' => 'the-code', 'state' => $request->state]);

        self::assertSame('t1', $token->accessToken);
        $form = self::form($auth->requests()[0]['body']);
        self::assertSame(['authorization_code'], $form['grant_type']);
        self::assertSame(['the-code'], $form['code']);
        self::assertSame(['https://app.example.com/callback'], $form['redirect_uri']);
        self::assertSame($query['code_challenge'], Pkce::challenge($form['code_verifier'][0]));
        self::assertSame('S256', $query['code_challenge_method']);
    }

    public function testAForgedCallbackNeverReachesTheTokenEndpoint(): void
    {
        $auth = $this->server([self::token('t1')]);
        $oauth = new OAuth2AuthorizationCode(
            new OAuth2Endpoints($auth->url('/token'), 'https://login.example.com/authorize'),
            new OAuth2Client('id', 'secret', 'https://app.example.com/callback'),
        );
        $request = $oauth->start();

        try {
            $oauth->complete($request, ['code' => 'the-code', 'state' => 'not-the-state']);
            self::fail('Expected an OAuth2Exception.');
        } catch (OAuth2Exception) {
            self::assertSame([], $auth->requests());
        }
    }

    public function testATokenEndpointErrorIsAnOAuth2Exception(): void
    {
        $auth = $this->server([['status' => 400, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"error":"invalid_client"}']]);
        $oauth = new OAuth2ClientCredentials(new OAuth2Endpoints($auth->url('/token')), new OAuth2Client('id', 'wrong'));

        $this->expectException(OAuth2Exception::class);
        $oauth->accessToken();
    }
}
