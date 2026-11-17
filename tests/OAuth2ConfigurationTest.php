<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\Security\OAuth2AuthorizationCode;
use Dmorenof\SdkRuntime\Security\OAuth2Client;
use Dmorenof\SdkRuntime\Security\OAuth2ClientCredentials;
use Dmorenof\SdkRuntime\Security\OAuth2Endpoints;
use Dmorenof\SdkRuntime\Security\OAuth2Options;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The three value objects every OAuth2 provider is built from validate their own data. */
final class OAuth2ConfigurationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function badUrls(): iterable
    {
        yield 'plain http' => ['http://auth.example.com/token'];
        yield 'credentials' => ['https://user:pw@auth.example.com/token'];
        yield 'fragment' => ['https://auth.example.com/token#x'];
        yield 'relative' => ['/token'];
        yield 'other scheme' => ['ftp://auth.example.com/token'];
    }

    #[DataProvider('badUrls')]
    public function testEndpointsRejectUrlsThatAreNotTlsOrLoopback(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OAuth2Endpoints($url);
    }

    public function testEndpointsAcceptHttpsAndLoopbackHttp(): void
    {
        self::assertSame('https://a.example/t', (new OAuth2Endpoints('https://a.example/t'))->tokenUrl);
        self::assertSame('http://localhost:9/t', (new OAuth2Endpoints('http://localhost:9/t', 'http://127.0.0.1/a'))->tokenUrl);
    }

    public function testClientRejectsEmptyValuesAndBadRedirectUris(): void
    {
        foreach ([fn () => new OAuth2Client(''), fn () => new OAuth2Client('id', ''), fn () => new OAuth2Client('id', null, 'http://evil.example/cb'), fn () => new OAuth2Client('id', null, 'https://a.example/cb#frag')] as $make) {
            try {
                $make();
                self::fail('Accepted invalid client data.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('com.example.app:/cb', (new OAuth2Client('id', null, 'com.example.app:/cb'))->redirectUri);
    }

    public function testClientHidesItsSecretFromDebugOutput(): void
    {
        self::assertStringNotContainsString('s3cret', print_r(new OAuth2Client('id', 's3cret'), true));
    }

    public function testOptionsRejectBadScopesAndNegativeLeeway(): void
    {
        foreach ([['a b'], [''], ['a"b']] as $scopes) {
            try {
                new OAuth2Options($scopes);
                self::fail('Accepted a bad scope.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new OAuth2Options(leeway: -1);
    }

    public function testEachGrantChecksWhatItNeeds(): void
    {
        $endpoints = new OAuth2Endpoints('https://auth.example.com/token');
        try {
            new OAuth2ClientCredentials($endpoints, new OAuth2Client('id'));
            self::fail('Client credentials accepted a public client.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        $this->expectException(\InvalidArgumentException::class);
        new OAuth2AuthorizationCode($endpoints, new OAuth2Client('id', null, 'https://app.example.com/cb'));
    }
}
