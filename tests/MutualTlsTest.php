<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\Configuration;
use Dmorenof\SdkRuntime\Security\ClientCertificate;
use Dmorenof\SdkRuntime\Security\SecurityApplier;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use Dmorenof\SdkRuntime\Transport\Psr18Options;
use Dmorenof\SdkRuntime\Transport\Psr18Transport;
use Dmorenof\SdkRuntime\Transport\RedirectPolicy;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class MutualTlsClient extends ApiClient
{
    protected const array SECURITY_SCHEMES = ['Mtls' => ['type' => 'mutualTLS', 'scheme' => null, 'location' => null, 'parameterName' => null]];

    public function ping(): mixed
    {
        return $this->executeArguments(['method' => 'GET', 'path' => '/ping', 'success' => [204], 'security' => [['Mtls' => []]]]);
    }
}

final class MutualTlsTest extends TestCase
{
    /** @param list<array<string, mixed>> $seen */
    private static function guzzle(array &$seen, ?\Closure $answer = null): Client
    {
        $handler = static function (RequestInterface $request, array $options) use (&$seen, $answer) {
            $seen[] = ['uri' => (string) $request->getUri(), 'options' => $options];
            return Create::promiseFor($answer !== null ? $answer($request) : new Response(204));
        };
        return new Client(['handler' => HandlerStack::create($handler)]);
    }

    public function testTheOptionsOfACertificateInOneFileAndInTwo(): void
    {
        self::assertSame(['cert' => '/c.pem'], ClientCertificate::fromFiles('/c.pem')->guzzleOptions());
        self::assertSame(['cert' => ['/c.pem', 'pw']], ClientCertificate::fromFiles('/c.pem', null, 'pw')->guzzleOptions());
        self::assertSame(['cert' => '/c.pem', 'ssl_key' => '/k.pem'], ClientCertificate::fromFiles('/c.pem', '/k.pem')->guzzleOptions());
        self::assertSame(['cert' => '/c.pem', 'ssl_key' => ['/k.pem', 'pw']], ClientCertificate::fromFiles('/c.pem', '/k.pem', 'pw')->guzzleOptions());
        self::assertSame([], ClientCertificate::configuredOnTheClient()->guzzleOptions());
        self::assertTrue(ClientCertificate::configuredOnTheClient()->isConfiguredOnTheClient());
        self::assertFalse(ClientCertificate::fromFiles('/c.pem')->isConfiguredOnTheClient());
    }

    public function testInvalidPathsAndPassphrasesAreRefusedAndTheDebugOutputHoldsNoSecret(): void
    {
        foreach ([['', null, null], ["/c\n.pem", null, null], ['/c.pem', '', null], ['/c.pem', "/k\0", null], ['/c.pem', null, ''], ['/c.pem', null, "p\0w"]] as [$certificate, $key, $password]) {
            try {
                ClientCertificate::fromFiles($certificate, $key, $password);
                self::fail('An invalid certificate was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertStringNotContainsString('hunter2', print_r(ClientCertificate::fromFiles('/c.pem', '/k.pem', 'hunter2'), true));
    }

    public function testASchemeOfTypeMutualTlsNeedsAClientCertificate(): void
    {
        $schemes = ['Mtls' => ['type' => 'mutualTLS', 'scheme' => null, 'location' => null, 'parameterName' => null]];
        $certificate = ClientCertificate::fromFiles('/c.pem');
        $applied = SecurityApplier::apply(new SecurityCredentials(['Mtls' => $certificate]), [['Mtls' => []]], $schemes);
        self::assertSame($certificate, $applied->certificate);
        self::assertSame([], $applied->headers);

        $this->expectException(\InvalidArgumentException::class);
        SecurityApplier::apply(new SecurityCredentials(['Mtls' => 'token']), [['Mtls' => []]], $schemes);
    }

    public function testACertificateIsNotAHeaderCredential(): void
    {
        $schemes = ['Key' => ['type' => 'apiKey', 'scheme' => null, 'location' => 'header', 'parameterName' => 'X-Key']];
        $this->expectException(\InvalidArgumentException::class);
        SecurityApplier::apply(new SecurityCredentials(['Key' => ClientCertificate::fromFiles('/c.pem')]), [['Key' => []]], $schemes);
    }

    public function testTheGuzzleTransportHandsTheCertificateToTheHandshakeOfTheCall(): void
    {
        $seen = [];
        $transport = new Psr18Transport(self::guzzle($seen));
        $transport->sendResponse(new Request('GET', 'https://api.example.test/ping'), false, ClientCertificate::fromFiles('/c.pem', '/k.pem', 'pw'));
        self::assertSame('/c.pem', $seen[0]['options']['cert']);
        self::assertSame(['/k.pem', 'pw'], $seen[0]['options']['ssl_key']);
        self::assertFalse($seen[0]['options']['http_errors']);
        self::assertFalse($seen[0]['options']['allow_redirects']);
    }

    public function testNoCertificateAddsNoTlsOptions(): void
    {
        $seen = [];
        $transport = new Psr18Transport(self::guzzle($seen));
        $transport->sendResponse(new Request('GET', 'https://api.example.test/ping'));
        self::assertArrayNotHasKey('cert', $seen[0]['options']);
        self::assertArrayNotHasKey('ssl_key', $seen[0]['options']);
    }

    public function testACertificateConfiguredOnTheClientAddsNothing(): void
    {
        $seen = [];
        $transport = new Psr18Transport(self::guzzle($seen));
        $transport->sendResponse(new Request('GET', 'https://api.example.test/ping'), false, ClientCertificate::configuredOnTheClient());
        self::assertArrayNotHasKey('cert', $seen[0]['options']);
    }

    public function testAClientThatIsNotGuzzleCannotTakeACertificatePerCall(): void
    {
        $other = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(204);
            }
        };
        $transport = new Psr18Transport($other);
        self::assertSame(204, $transport->sendResponse(new Request('GET', 'https://api.example.test/ping'), false, ClientCertificate::configuredOnTheClient())->getStatusCode());
        $this->expectException(\LogicException::class);
        $transport->sendResponse(new Request('GET', 'https://api.example.test/ping'), false, ClientCertificate::fromFiles('/c.pem'));
    }

    public function testTheCertificateIsPresentedOnlyToTheHostTheCallWasMadeTo(): void
    {
        $seen = [];
        $client = self::guzzle($seen, static fn (RequestInterface $request): Response => $request->getUri()->getHost() === 'api.example.test'
            ? new Response(302, ['Location' => ($request->getUri()->getPath() === '/ping' ? 'https://api.example.test/next' : 'https://other.example.test/x')])
            : new Response(204));
        $transport = new Psr18Transport($client, new Psr18Options(redirectPolicy: new RedirectPolicy(maxRedirects: 3)));
        $transport->sendResponse(new Request('GET', 'https://api.example.test/ping'), false, ClientCertificate::fromFiles('/c.pem'));
        self::assertCount(3, $seen);
        self::assertSame('/c.pem', $seen[0]['options']['cert']);
        self::assertSame('/c.pem', $seen[1]['options']['cert'], 'the same host keeps it');
        self::assertArrayNotHasKey('cert', $seen[2]['options'], 'another host never sees it');
    }

    public function testAnOperationThatRequiresMutualTlsSendsItsCertificate(): void
    {
        $seen = [];
        $config = new Configuration();
        $config->baseUrl = 'https://api.example.test';
        $config->httpClient = self::guzzle($seen);
        $config->securityCredentials = new SecurityCredentials(['Mtls' => ClientCertificate::fromFiles('/c.pem', '/k.pem')]);
        MutualTlsClient::connect($config)->ping();
        self::assertSame('https://api.example.test/ping', $seen[0]['uri']);
        self::assertSame('/c.pem', $seen[0]['options']['cert']);
        self::assertSame('/k.pem', $seen[0]['options']['ssl_key']);
    }

    public function testAnOperationThatRequiresMutualTlsFailsWithoutACertificate(): void
    {
        $config = new Configuration();
        $config->baseUrl = 'https://api.example.test';
        $config->transport = new FakeTransport(new HttpResponse(204, ''));
        $this->expectException(\InvalidArgumentException::class);
        MutualTlsClient::connect($config)->ping();
    }

    public function testACustomTransportMustPresentTheCertificateItself(): void
    {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $config = new Configuration();
        $config->baseUrl = 'https://api.example.test';
        $config->transport = $transport;
        $config->securityCredentials = new SecurityCredentials(['Mtls' => ClientCertificate::configuredOnTheClient()]);
        MutualTlsClient::connect($config)->ping();
        self::assertCount(1, $transport->sent);

        $config->securityCredentials = new SecurityCredentials(['Mtls' => ClientCertificate::fromFiles('/c.pem')]);
        $this->expectException(\LogicException::class);
        MutualTlsClient::connect($config)->ping();
    }
}
