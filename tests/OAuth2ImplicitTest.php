<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\Security\AuthorizationRequest;
use Dmorenof\SdkRuntime\Security\InMemoryTokenStore;
use Dmorenof\SdkRuntime\Security\OAuth2Client;
use Dmorenof\SdkRuntime\Security\OAuth2Exception;
use Dmorenof\SdkRuntime\Security\OAuth2Implicit;
use Dmorenof\SdkRuntime\Security\OAuth2Options;
use Dmorenof\SdkRuntime\Security\ReauthorizationRequiredException;
use Dmorenof\SdkRuntime\Security\SecurityApplier;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;
use PHPUnit\Framework\TestCase;

final class OAuth2ImplicitTest extends TestCase
{
    private const string AUTH_URL = 'https://auth.example.com/authorize';
    private const string REDIRECT = 'https://app.example.com/callback';

    private static function flow(?\Closure $clock = null, ?InMemoryTokenStore $store = null, string $subject = ''): OAuth2Implicit
    {
        return new OAuth2Implicit(self::AUTH_URL, new OAuth2Client('app', redirectUri: self::REDIRECT), new OAuth2Options(['read', 'write'], store: $store, subject: $subject, clock: $clock));
    }

    /** @return array<string, string> */
    private static function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        return array_map('strval', $query);
    }

    public function testTheAuthorizationUrlAsksForATokenWithAFreshState(): void
    {
        $request = self::flow()->start(['audience' => 'api']);
        $query = self::query($request->url);
        self::assertStringStartsWith(self::AUTH_URL . '?', $request->url);
        self::assertSame(['response_type' => 'token', 'client_id' => 'app', 'redirect_uri' => self::REDIRECT, 'scope' => 'read write', 'state' => $request->state, 'audience' => 'api'], $query);
        self::assertNotSame($request->state, self::flow()->start()->state);
    }

    public function testExtraParametersCannotReplaceProtocolOnes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::flow()->start(['redirect_uri' => 'https://evil.example/']);
    }

    public function testItNeedsARedirectUriAndASecureAuthorizationUrl(): void
    {
        foreach ([[self::AUTH_URL, new OAuth2Client('app')], ['http://auth.example.com/authorize', new OAuth2Client('app', redirectUri: self::REDIRECT)]] as [$url, $client]) {
            try {
                new OAuth2Implicit($url, $client);
                self::fail('A bad configuration was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTheTokenInTheFragmentOfTheRedirectedUrlIsStoredAndUsed(): void
    {
        $now = 1000;
        $flow = self::flow(static function () use (&$now): int { return $now; });
        $request = $flow->start();
        self::assertFalse($flow->isAuthorized());
        $token = $flow->complete($request, self::REDIRECT . '#access_token=abc&token_type=bearer&expires_in=3600&state=' . rawurlencode($request->state));
        self::assertSame('abc', $token->accessToken);
        self::assertSame(4600, $token->expiresAt);
        self::assertNull($token->refreshToken);
        self::assertTrue($flow->isAuthorized());
        self::assertSame('abc', $flow->accessToken());
    }

    public function testAnExpiredTokenCannotBeRenewedSoTheUserAuthorizesAgain(): void
    {
        $now = 1000;
        $flow = self::flow(static function () use (&$now): int { return $now; });
        $request = $flow->start();
        $flow->complete($request, self::REDIRECT . '#access_token=abc&expires_in=60&state=' . rawurlencode($request->state));
        $now = 5000;
        $this->expectException(ReauthorizationRequiredException::class);
        $flow->accessToken();
    }

    public function testThereIsNoTokenBeforeTheFlowIsCompletedAndInvalidateForgetsIt(): void
    {
        $flow = self::flow();
        try {
            $flow->accessToken();
            self::fail('A token appeared from nowhere.');
        } catch (ReauthorizationRequiredException) {
            self::assertTrue(true);
        }
        $request = $flow->start();
        $flow->complete($request, self::REDIRECT . '#access_token=abc&state=' . rawurlencode($request->state));
        $flow->invalidate();
        $this->expectException(ReauthorizationRequiredException::class);
        $flow->accessToken();
    }

    /** @return array<string, array{string}> */
    public static function badResponses(): array
    {
        return [
            'another state' => ['#access_token=abc&state=other'],
            'no state' => ['#access_token=abc'],
            'an error' => ['#error=access_denied&state=@STATE@'],
            'no token' => ['#state=@STATE@'],
            'a token that is not a bearer token' => ['#access_token=abc&token_type=mac&state=@STATE@'],
            'the token in the query, not the fragment' => ['?access_token=abc&state=@STATE@'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badResponses')]
    public function testAResponseThatIsNotAGoodTokenIsRefused(string $tail): void
    {
        $flow = self::flow();
        $request = $flow->start();
        $this->expectException(OAuth2Exception::class);
        try {
            $flow->complete($request, self::REDIRECT . str_replace('@STATE@', rawurlencode($request->state), $tail));
        } finally {
            self::assertFalse($flow->isAuthorized());
        }
    }

    public function testAnotherRedirectedUrlAndAnExpiredRequestAreRefused(): void
    {
        $now = 1000;
        $flow = self::flow(static function () use (&$now): int { return $now; });
        $request = $flow->start();
        try {
            $flow->complete($request, 'https://evil.example/callback#access_token=abc&state=' . rawurlencode($request->state));
            self::fail('A foreign redirect was accepted.');
        } catch (OAuth2Exception) {
            self::assertFalse($flow->isAuthorized());
        }
        $now = 5000;
        $this->expectException(OAuth2Exception::class);
        $flow->complete($request, self::REDIRECT . '#access_token=abc&state=' . rawurlencode($request->state));
    }

    public function testTheRequestSurvivesBeingKeptBetweenTheRedirectAndTheCallback(): void
    {
        $flow = self::flow();
        $request = $flow->start();
        $kept = AuthorizationRequest::fromArray($request->toArray());
        $token = $flow->complete($kept, self::REDIRECT . '#access_token=abc&state=' . rawurlencode($request->state));
        self::assertSame('abc', $token->accessToken);
    }

    public function testTheFlowWorksAsABearerCredentialAndKeepsUsersApartInASharedStore(): void
    {
        $store = new InMemoryTokenStore();
        $ada = self::flow(null, $store, 'ada');
        $request = $ada->start();
        $ada->complete($request, self::REDIRECT . '#access_token=for-ada&state=' . rawurlencode($request->state));
        $schemes = ['OAuth' => ['type' => 'oauth2', 'scheme' => null, 'location' => null, 'parameterName' => null]];
        $applied = SecurityApplier::apply(new SecurityCredentials(['OAuth' => $ada]), [['OAuth' => []]], $schemes);
        self::assertSame(['Authorization' => 'Bearer for-ada'], $applied->headers);
        self::assertFalse(self::flow(null, $store, 'grace')->isAuthorized());
    }
}
