<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\Security\DiscoveryOptions;
use Dmorenof\SdkRuntime\Security\OAuth2AuthorizationCode;
use Dmorenof\SdkRuntime\Security\OAuth2Client;
use Dmorenof\SdkRuntime\Security\OAuth2ClientCredentials;
use Dmorenof\SdkRuntime\Security\OAuth2Exception;
use Dmorenof\SdkRuntime\Security\OAuth2Options;
use Dmorenof\SdkRuntime\Security\OpenIdDiscovery;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class OpenIdClient extends ApiClient
{
    protected const array SECURITY_SCHEMES = [
        'Oidc' => ['type' => 'openIdConnect', 'scheme' => null, 'location' => null, 'parameterName' => null, 'openIdConnectUrl' => 'https://login.example.com/tenant/.well-known/openid-configuration'],
        'Key' => ['type' => 'apiKey', 'scheme' => null, 'location' => 'header', 'parameterName' => 'X-Key'],
    ];
}

final class OpenIdDiscoveryTest extends TestCase
{
    private const string ISSUER = 'https://login.example.com/tenant';

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

    /** @param array<string, mixed> $override */
    private static function document(array $override = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($override + [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER . '/authorize',
            'token_endpoint' => self::ISSUER . '/token',
            'jwks_uri' => self::ISSUER . '/keys',
            'code_challenge_methods_supported' => ['S256', 'plain'],
            'scopes_supported' => ['openid', 'profile'],
        ], JSON_THROW_ON_ERROR));
    }

    public function testTheDocumentIsFetchedFromTheWellKnownUrlOfTheIssuer(): void
    {
        $http = $this->http([self::document()]);
        $configuration = OpenIdDiscovery::forIssuer(self::ISSUER, new DiscoveryOptions($http))->configuration();
        self::assertSame('GET', $http->requests[0]->getMethod());
        self::assertSame(self::ISSUER . '/.well-known/openid-configuration', (string) $http->requests[0]->getUri());
        self::assertSame('application/json', $http->requests[0]->getHeaderLine('Accept'));
        self::assertSame(self::ISSUER, $configuration->issuer);
        self::assertSame(self::ISSUER . '/authorize', $configuration->authorizationEndpoint);
        self::assertSame(self::ISSUER . '/token', $configuration->tokenEndpoint);
        self::assertSame(['openid', 'profile'], $configuration->scopes);
        self::assertSame(self::ISSUER . '/keys', $configuration->jwksUri);
        self::assertTrue($configuration->supportsPkce());
    }

    public function testTheKeysAndSigningAlgorithmsOfTheProviderAreRead(): void
    {
        $http = $this->http([self::document(['id_token_signing_alg_values_supported' => ['RS256', 'ES256']])]);
        $configuration = OpenIdDiscovery::forIssuer(self::ISSUER, new DiscoveryOptions($http))->configuration();
        self::assertSame(['RS256', 'ES256'], $configuration->idTokenSigningAlgorithms);
    }

    public function testAnInsecureKeysUriIsRefused(): void
    {
        $http = $this->http([self::document(['jwks_uri' => 'http://login.example.com/keys'])]);
        $this->expectException(OAuth2Exception::class);
        OpenIdDiscovery::forIssuer(self::ISSUER, new DiscoveryOptions($http))->configuration();
    }

    public function testAnIssuerWithAFinalSlashIsMatchedExactly(): void
    {
        $slash = 'https://tenant.auth0.example/';
        $document = static fn (string $issuer): Response => new Response(200, [], json_encode(['issuer' => $issuer, 'token_endpoint' => 'https://tenant.auth0.example/oauth/token'], JSON_THROW_ON_ERROR));
        $http = $this->http([$document($slash)]);
        $configuration = OpenIdDiscovery::forIssuer($slash, new DiscoveryOptions($http))->configuration();
        self::assertSame($slash, $configuration->issuer);
        self::assertSame('https://tenant.auth0.example/.well-known/openid-configuration', (string) $http->requests[0]->getUri());

        $this->expectException(OAuth2Exception::class); // asked for with the slash, answered without it
        OpenIdDiscovery::forIssuer($slash, new DiscoveryOptions($this->http([$document('https://tenant.auth0.example')])))->configuration();
    }

    public function testTheEndpointsFeedTheOAuth2Flows(): void
    {
        $http = $this->http([self::document(), new Response(200, [], '{"access_token":"abc","token_type":"Bearer","expires_in":3600}')]);
        $endpoints = OpenIdDiscovery::forIssuer(self::ISSUER, new DiscoveryOptions($http))->configuration()->endpoints();
        self::assertSame(self::ISSUER . '/token', $endpoints->tokenUrl);
        self::assertSame(self::ISSUER, $endpoints->issuer);

        $flow = new OAuth2AuthorizationCode($endpoints, new OAuth2Client('app', redirectUri: 'https://app.example.com/cb'));
        self::assertStringStartsWith(self::ISSUER . '/authorize?', $flow->start()->url);

        $credentials = new OAuth2ClientCredentials($endpoints, new OAuth2Client('app', 's3cret'), new OAuth2Options(http: $http));
        self::assertSame('abc', $credentials->accessToken());
        self::assertSame(self::ISSUER . '/token', (string) $http->requests[1]->getUri());
    }

    public function testTheDocumentIsKeptForItsTimeToLiveAndThenFetchedAgain(): void
    {
        $now = 1000;
        $http = $this->http([self::document(), self::document(['token_endpoint' => self::ISSUER . '/token2'])]);
        $discovery = OpenIdDiscovery::forIssuer(self::ISSUER, new DiscoveryOptions($http, 600, static function () use (&$now): int { return $now; }));
        $discovery->configuration();
        $now = 1500;
        self::assertSame(self::ISSUER . '/token', $discovery->configuration()->tokenEndpoint);
        self::assertCount(1, $http->requests);
        $now = 1700;
        self::assertSame(self::ISSUER . '/token2', $discovery->configuration()->tokenEndpoint);
        $discovery->forget();
        $this->expectException(\LogicException::class); // forgetting makes the next use fetch again: nothing scripted is left
        $discovery->configuration();
    }

    /** @return array<string, array{ResponseInterface}> */
    public static function untrustedDocuments(): array
    {
        return [
            'a status that is not 200' => [new Response(404, [], 'nope')],
            'not JSON' => [new Response(200, [], '<html>')],
            'a list' => [new Response(200, [], '[1,2]')],
            'no issuer' => [new Response(200, [], '{"token_endpoint":"https://login.example.com/tenant/token"}')],
            'another issuer' => [self::document(['issuer' => 'https://evil.example/tenant'])],
            'an issuer with a trailing slash' => [self::document(['issuer' => self::ISSUER . '/'])],
            'an insecure token endpoint' => [self::document(['token_endpoint' => 'http://login.example.com/token'])],
            'an endpoint that is not a string' => [self::document(['authorization_endpoint' => ['x']])],
            'a document that is too large' => [new Response(200, [], '{"issuer":"' . str_repeat('a', 1_100_000) . '"}')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('untrustedDocuments')]
    public function testADocumentThatCannotBeTrustedIsRefused(ResponseInterface $response): void
    {
        $this->expectException(OAuth2Exception::class);
        OpenIdDiscovery::forIssuer(self::ISSUER, new DiscoveryOptions($this->http([$response])))->configuration();
    }

    public function testAnUrlFromAContractIsTrustedOnlyWhenTheIssuerIsOnTheSameHost(): void
    {
        $url = self::ISSUER . '/.well-known/openid-configuration';
        self::assertSame(self::ISSUER, OpenIdDiscovery::forUrl($url, new DiscoveryOptions($this->http([self::document()])))->configuration()->issuer);

        $this->expectException(OAuth2Exception::class);
        OpenIdDiscovery::forUrl($url, new DiscoveryOptions($this->http([self::document(['issuer' => 'https://evil.example/tenant'])])))->configuration();
    }

    public function testAProviderWithoutATokenEndpointCannotFeedTheCodeFlowAndOnePkceNoteIsHonored(): void
    {
        $http = $this->http([self::document(['token_endpoint' => null, 'code_challenge_methods_supported' => ['plain']])]);
        $configuration = OpenIdDiscovery::forIssuer(self::ISSUER, new DiscoveryOptions($http))->configuration();
        self::assertNull($configuration->tokenEndpoint);
        self::assertFalse($configuration->supportsPkce());
        $this->expectException(OAuth2Exception::class);
        $configuration->endpoints();
    }

    public function testAnInsecureIssuerOrUrlIsRefusedBeforeAnyRequest(): void
    {
        foreach ([fn () => OpenIdDiscovery::forIssuer('http://login.example.com'), fn () => OpenIdDiscovery::forUrl('http://login.example.com/.well-known/openid-configuration')] as $build) {
            try {
                $build();
                self::fail('An insecure URL was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new DiscoveryOptions(ttl: -1);
    }

    public function testAnSdkOffersTheDiscoveryOfItsOpenIdConnectScheme(): void
    {
        $http = $this->http([self::document()]);
        $discovery = OpenIdClient::openIdDiscovery('Oidc', new DiscoveryOptions($http));
        self::assertSame(self::ISSUER, $discovery->configuration()->issuer);
        self::assertSame(self::ISSUER . '/.well-known/openid-configuration', (string) $http->requests[0]->getUri());
        foreach (['Key', 'Missing'] as $scheme) {
            try {
                OpenIdClient::openIdDiscovery($scheme);
                self::fail('A scheme without discovery was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
