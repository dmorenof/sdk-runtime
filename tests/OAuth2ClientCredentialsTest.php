<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiException;
use Dmorenof\SdkRuntime\Security\InMemoryTokenStore;
use Dmorenof\SdkRuntime\Security\OAuth2ClientCredentials;
use Dmorenof\SdkRuntime\Security\OAuth2Token;
use Dmorenof\SdkRuntime\Security\SecurityApplier;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;
use Dmorenof\SdkRuntime\Tests\Fixtures\TestClient;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use Dmorenof\SdkRuntime\Transport\TransportInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\TestCase;

final class OAuth2ClientCredentialsTest extends TestCase
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

    private static function token(string $access, ?int $expiresIn = 3600, ?string $refresh = null): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(array_filter([
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'refresh_token' => $refresh,
        ], static fn ($v) => $v !== null), JSON_THROW_ON_ERROR));
    }

    public function testObtainsAndCachesTokenWithBasicClientAuthentication(): void
    {
        $http = $this->http([self::token('t1')]);
        $oauth = new OAuth2ClientCredentials(self::TOKEN_URL, 'id:1', 'se cret', $http, ['read', 'write']);

        self::assertSame('t1', $oauth->accessToken());
        self::assertSame('t1', $oauth->accessToken());

        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('Basic ' . base64_encode('id%3A1:se+cret'), $request->getHeaderLine('Authorization'));
        parse_str((string) $request->getBody(), $form);
        self::assertSame(['grant_type' => 'client_credentials', 'scope' => 'read write'], $form);
    }

    public function testCanSendClientCredentialsInBody(): void
    {
        $http = $this->http([self::token('t1')]);
        $oauth = new OAuth2ClientCredentials(self::TOKEN_URL, 'id', 'secret', $http, credentialsInBody: true);

        $oauth->accessToken();

        self::assertSame('', $http->requests[0]->getHeaderLine('Authorization'));
        parse_str((string) $http->requests[0]->getBody(), $form);
        self::assertSame('id', $form['client_id']);
        self::assertSame('secret', $form['client_secret']);
    }

    public function testRequestsNewTokenAfterExpiryWithLeeway(): void
    {
        $now = 1000;
        $http = $this->http([self::token('t1', 100), self::token('t2', 100)]);
        $oauth = new OAuth2ClientCredentials(self::TOKEN_URL, 'id', 'secret', $http, leeway: 30, clock: function () use (&$now): int {
            return $now;
        });

        self::assertSame('t1', $oauth->accessToken());
        $now = 1069;
        self::assertSame('t1', $oauth->accessToken());
        $now = 1070; // 1100 - 30 leeway: stale
        self::assertSame('t2', $oauth->accessToken());
    }

    public function testRenewsWithRefreshTokenAndKeepsItWhenServerOmitsANewOne(): void
    {
        $now = 0;
        $http = $this->http([self::token('t1', 10, 'r1'), self::token('t2', 10), self::token('t3', 10)]);
        $oauth = new OAuth2ClientCredentials(self::TOKEN_URL, 'id', 'secret', $http, leeway: 0, clock: function () use (&$now): int {
            return $now;
        });

        $oauth->accessToken();
        $now = 20;
        self::assertSame('t2', $oauth->accessToken());
        $now = 40;
        self::assertSame('t3', $oauth->accessToken());

        parse_str((string) $http->requests[1]->getBody(), $first);
        parse_str((string) $http->requests[2]->getBody(), $second);
        self::assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'r1'], $first);
        self::assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'r1'], $second);
    }

    public function testFallsBackToNewGrantWhenRefreshTokenIsRejected(): void
    {
        $now = 0;
        $http = $this->http([
            self::token('t1', 10, 'r1'),
            new Response(400, [], '{"error":"invalid_grant"}'),
            self::token('t2', 10),
        ]);
        $oauth = new OAuth2ClientCredentials(self::TOKEN_URL, 'id', 'secret', $http, leeway: 0, clock: function () use (&$now): int {
            return $now;
        });

        $oauth->accessToken();
        $now = 20;

        self::assertSame('t2', $oauth->accessToken());
        parse_str((string) $http->requests[2]->getBody(), $form);
        self::assertSame('client_credentials', $form['grant_type']);
    }

    public function testFailuresNeverLeakSecrets(): void
    {
        $http = $this->http([new Response(401, [], '{"error":"invalid_client","error_description":"bad secret-value"}')]);
        $oauth = new OAuth2ClientCredentials(self::TOKEN_URL, 'id', 'secret-value', $http);

        try {
            $oauth->accessToken();
            self::fail('Expected failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('OAuth2 token request failed with HTTP 401: invalid_client.', $e->getMessage());
        }
        self::assertStringNotContainsString('secret-value', print_r($oauth, true));
    }

    public function testRejectsUnusableTokenResponses(): void
    {
        foreach ([
            new Response(200, [], '{"access_token":"x","token_type":"mac"}'),
            new Response(200, [], '{"token_type":"Bearer"}'),
            new Response(200, [], 'not json'),
        ] as $response) {
            $oauth = new OAuth2ClientCredentials(self::TOKEN_URL, 'id', 'secret', $this->http([$response]));
            $this->expectException(\RuntimeException::class);
            $oauth->accessToken();
        }
    }

    public function testValidatesConfiguration(): void
    {
        $http = $this->http([]);
        foreach ([
            ['/relative', 'id', 'secret', []],
            ['ftp://auth.example.com/token', 'id', 'secret', []],
            ['https://user:pw@auth.example.com/token', 'id', 'secret', []],
            [self::TOKEN_URL, '', 'secret', []],
            [self::TOKEN_URL, 'id', 'secret', ['bad scope']],
        ] as [$url, $id, $secret, $scopes]) {
            try {
                new OAuth2ClientCredentials($url, $id, $secret, $http, $scopes);
                self::fail("Accepted $url");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSharedStoreServesSeveralProviders(): void
    {
        $store = new InMemoryTokenStore();
        $http = $this->http([self::token('t1')]);
        $a = new OAuth2ClientCredentials(self::TOKEN_URL, 'id', 'secret', $http, store: $store);
        $b = new OAuth2ClientCredentials(self::TOKEN_URL, 'id', 'secret', $http, store: $store);

        self::assertSame('t1', $a->accessToken());
        self::assertSame('t1', $b->accessToken());
        self::assertCount(1, $http->requests);
    }

    public function testTokenValueObjectHidesSecrets(): void
    {
        self::assertStringNotContainsString('secret-token', print_r(new OAuth2Token('secret-token', null, 'refresh'), true));
        $this->expectException(\InvalidArgumentException::class);
        new OAuth2Token("bad\ntoken");
    }

    public function testAppliesProviderTokenToOAuth2AndBearerSchemes(): void
    {
        $oauth = new OAuth2ClientCredentials(self::TOKEN_URL, 'id', 'secret', $this->http([self::token('t1')]));
        $schemes = ['O' => ['type' => 'oauth2', 'scheme' => null, 'location' => null, 'parameterName' => null]];
        $headers = [];
        $query = [];
        $used = [];

        SecurityApplier::apply(new SecurityCredentials(['O' => $oauth]), [['O' => ['read']]], $schemes, $headers, $query, $used);

        self::assertSame(['Authorization' => 'Bearer t1'], $headers);
        self::assertSame([$oauth], $used);
    }

    public function testClientReplaysOnceAfter401WithRenewedToken(): void
    {
        $http = $this->http([self::token('old'), self::token('new')]);
        $oauth = new OAuth2ClientCredentials(self::TOKEN_URL, 'id', 'secret', $http);
        $transport = new class implements TransportInterface {
            /** @var list<array<string,string>> */
            public array $headers = [];
            public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
            {
                $this->headers[] = $headers;
                return $headers['Authorization'] === 'Bearer new'
                    ? new HttpResponse(200, '{"id":1,"name":"Grace"}')
                    : new HttpResponse(401, '{}');
            }
        };
        $client = TestClient::connect('https://api.example.com', securityCredentials: new SecurityCredentials(['Bearer' => $oauth]), transport: $transport);

        self::assertSame('Grace', $client->getUser(1)->name);
        self::assertSame(['Bearer old', 'Bearer new'], array_column($transport->headers, 'Authorization'));
    }

    public function testClientDoesNotLoopWhenRenewedTokenIsAlsoRejected(): void
    {
        $http = $this->http([self::token('a'), self::token('b')]);
        $oauth = new OAuth2ClientCredentials(self::TOKEN_URL, 'id', 'secret', $http);
        $transport = new class implements TransportInterface {
            public int $calls = 0;
            public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
            {
                $this->calls++;
                return new HttpResponse(401, '{}');
            }
        };
        $client = TestClient::connect('https://api.example.com', securityCredentials: new SecurityCredentials(['Bearer' => $oauth]), transport: $transport);

        try {
            $client->getUser(1);
            self::fail('Expected ApiException.');
        } catch (ApiException $e) {
            self::assertSame(401, $e->getCode() ?: 401);
        }
        self::assertSame(2, $transport->calls);
    }

    public function testStaticTokensAreNeverReplayed(): void
    {
        $transport = new class implements TransportInterface {
            public int $calls = 0;
            public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
            {
                $this->calls++;
                return new HttpResponse(401, '{}');
            }
        };
        $client = TestClient::connect('https://api.example.com', securityCredentials: new SecurityCredentials(['Bearer' => 'static']), transport: $transport);

        try {
            $client->getUser(1);
            self::fail('Expected ApiException.');
        } catch (ApiException) {
            self::assertSame(1, $transport->calls);
        }
    }
}
