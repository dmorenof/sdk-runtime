<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\Security\ClientCertificate;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;
use Dmorenof\SdkRuntime\Tests\Support\Cfg;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Mutual TLS on a real handshake: a TLS server on 127.0.0.1 that requires a client certificate signed by a CA made here, and the default
 * Guzzle transport presenting the certificate of the `mutualTLS` scheme. The other tests of the scheme check the options handed to a fake
 * client; this proves the certificate is presented, accepted when the CA signed it and refused when it did not or when there is none.
 */
final class RealMutualTlsTest extends TestCase
{
    private string $directory;
    /** @var resource|null */
    private $process = null;
    /** @var array<int, resource> */
    private array $pipes = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mtls-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /**
     * A certificate authority: its certificate and key.
     *
     * @return array{\OpenSSLCertificate, \OpenSSLAsymmetricKey}
     */
    private static function authority(string $name): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key, 'OpenSSL cannot make keys here.');
        $request = openssl_csr_new(['commonName' => $name], $key, ['digest_alg' => 'sha256']);
        self::assertNotFalse($request);
        $certificate = openssl_csr_sign($request, null, $key, 1, ['digest_alg' => 'sha256']);
        self::assertNotFalse($certificate);
        return [$certificate, $key];
    }

    /** @param array{\OpenSSLCertificate, \OpenSSLAsymmetricKey} $authority @return array{string, string} PEM of the certificate and of its key */
    private static function issue(string $commonName, array $authority): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $request = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
        self::assertNotFalse($request);
        $certificate = openssl_csr_sign($request, $authority[0], $authority[1], 1, ['digest_alg' => 'sha256'], random_int(2, PHP_INT_MAX >> 8));
        self::assertNotFalse($certificate);
        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem);
        return [$certificatePem, $keyPem];
    }

    private function file(string $name, string $contents): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, $contents);
        return $path;
    }

    /** @return array{string, string} The server's URL and its request log */
    private function startServer(string $serverPem, string $caPem): array
    {
        $log = $this->file('requests.log', '');
        $this->process = proc_open([PHP_BINARY, __DIR__ . '/Support/tls-server.php', $this->file('server.pem', $serverPem), $this->file('ca.pem', $caPem), $log], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        self::assertIsResource($this->process);
        stream_set_timeout($this->pipes[1], 15);
        $port = trim((string) fgets($this->pipes[1]));
        self::assertTrue(ctype_digit($port), 'The TLS server printed no port.');
        return ['https://localhost:' . $port, $log];
    }

    private static function client(string $url, ?ClientCertificate $certificate, string $caFile): ApiClient
    {
        $credentials = $certificate === null ? null : new SecurityCredentials(['Mtls' => $certificate]);
        $configuration = Cfg::make($url, new Client(['http_errors' => false, 'verify' => $caFile, 'timeout' => 10]), securityCredentials: $credentials);
        return new class ($configuration) extends ApiClient {
            protected const array SECURITY_SCHEMES = ['Mtls' => ['type' => 'mutualTLS', 'scheme' => null, 'location' => null, 'parameterName' => null]];

            public function ping(): mixed
            {
                return $this->executeArguments(['method' => 'GET', 'path' => '/ping', 'success' => [204], 'security' => [['Mtls' => []]]]);
            }
        };
    }

    public function testTheCertificateIsPresentedAndAcceptedWhenTheAuthoritySignedIt(): void
    {
        $authority = self::authority('Test CA');
        openssl_x509_export($authority[0], $caPem);
        [$serverCertificate, $serverKey] = self::issue('localhost', $authority);
        [$clientCertificate, $clientKey] = self::issue('the-client', $authority);
        [$url, $log] = $this->startServer($serverCertificate . $serverKey, $caPem);
        $certificate = ClientCertificate::fromFiles($this->file('client.crt', $clientCertificate), $this->file('client.key', $clientKey));

        self::client($url, $certificate, $this->file('trust.pem', $caPem))->ping();

        $requests = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        self::assertSame([['method' => 'GET', 'target' => '/ping', 'clientCn' => 'the-client']], $requests);
    }

    public function testACertificateInOneFileWithItsKeyWorksToo(): void
    {
        $authority = self::authority('Test CA');
        openssl_x509_export($authority[0], $caPem);
        [$serverCertificate, $serverKey] = self::issue('localhost', $authority);
        [$clientCertificate, $clientKey] = self::issue('combined', $authority);
        [$url, $log] = $this->startServer($serverCertificate . $serverKey, $caPem);

        self::client($url, ClientCertificate::fromFiles($this->file('client.pem', $clientCertificate . $clientKey)), $this->file('trust.pem', $caPem))->ping();

        self::assertStringContainsString('"clientCn":"combined"', (string) file_get_contents($log));
    }

    public function testACertificateFromAnotherAuthorityIsRefusedByTheHandshake(): void
    {
        $authority = self::authority('Test CA');
        $stranger = self::authority('Somebody else');
        openssl_x509_export($authority[0], $caPem);
        [$serverCertificate, $serverKey] = self::issue('localhost', $authority);
        [$clientCertificate, $clientKey] = self::issue('impostor', $stranger);
        [$url, $log] = $this->startServer($serverCertificate . $serverKey, $caPem);
        $certificate = ClientCertificate::fromFiles($this->file('client.crt', $clientCertificate), $this->file('client.key', $clientKey));
        $client = self::client($url, $certificate, $this->file('trust.pem', $caPem));

        try {
            $client->ping();
            self::fail('A certificate of another authority was accepted.');
        } catch (ClientExceptionInterface) {
            self::assertSame('', trim((string) file_get_contents($log)), 'The request reached the application.');
        }
    }

    public function testWithoutACertificateTheHandshakeFails(): void
    {
        $authority = self::authority('Test CA');
        openssl_x509_export($authority[0], $caPem);
        [$serverCertificate, $serverKey] = self::issue('localhost', $authority);
        [$url, $log] = $this->startServer($serverCertificate . $serverKey, $caPem);
        // the client holds no certificate of its own: it declares it presents one, and does not
        $client = self::client($url, ClientCertificate::configuredOnTheClient(), $this->file('trust.pem', $caPem));

        try {
            $client->ping();
            self::fail('A client without a certificate was served.');
        } catch (ClientExceptionInterface) {
            self::assertSame('', trim((string) file_get_contents($log)));
        }
    }
}
