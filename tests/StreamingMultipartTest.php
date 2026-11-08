<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Transport\HttpMiddlewareInterface;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use Dmorenof\SdkRuntime\Transport\Psr18Transport;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;

class StreamingPayload extends DTO
{
    public const array REQUIRED_FIELDS = ['file'];
    public string|UploadedFileInterface $file;
    public string|UploadedFileInterface $second;
}

class StreamingProbeClient extends ApiClient
{
    public function submit(?DTO $body): void
    {
        $this->executeOperation(['method' => 'POST', 'path' => '/uploads', 'success' => [204],
            'requestMediaType' => 'multipart/form-data', 'bodyParts' => [
                'file' => ['contentType' => null, 'filename' => 'file.bin'],
                'second' => ['contentType' => null, 'filename' => 'second.bin'],
            ]], queryParameters: ['body' => 'query +'], headerParameters: ['X-Trace' => 'trace'], body: $body);
    }
}

final class StreamingMultipartTest extends TestCase
{
    public function testPsr18ReceivesUnreadMultipartStreamAndRepeatSendsRewindFiles(): void
    {
        $counter = (object) ['reads' => 0];
        $source = Utils::streamFor("\x00\xff\r\nbytes");
        $tracked = FnStream::decorate($source, ['read' => static function ($length) use ($source, $counter): string {
            ++$counter->reads;
            return $source->read($length);
        }]);
        $http = new class($counter) implements ClientInterface {
            public array $entryReads = [];
            public array $exitReads = [];
            public array $bodies = [];
            public function __construct(private \stdClass $counter) {}
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                TestCase::assertInstanceOf(MultipartStream::class, $request->getBody());
                TestCase::assertSame(0, $request->getBody()->tell());
                TestCase::assertSame('https://example.test/uploads?body=query%20%2B', (string) $request->getUri());
                TestCase::assertSame('trace', $request->getHeaderLine('X-Trace'));
                $this->entryReads[] = $this->counter->reads;
                $bytes = '';
                while (!$request->getBody()->eof()) {
                    $bytes .= $request->getBody()->read(8192);
                }
                $this->bodies[] = $bytes;
                $this->exitReads[] = $this->counter->reads;
                return new Response(204);
            }
        };
        $upload = new UploadedFile($tracked, 9, UPLOAD_ERR_OK, 'original.bin', 'application/octet-stream');
        $body = new StreamingPayload((object) ['file' => $upload]);
        $client = StreamingProbeClient::connect('https://example.test', $http);
        $client->submit($body);
        $client->submit($body);
        self::assertSame(0, $http->entryReads[0]);
        self::assertSame($http->exitReads[0], $http->entryReads[1]);
        foreach ($http->bodies as $bytes) {
            self::assertStringContainsString("\r\n\r\n\x00\xff\r\nbytes\r\n", $bytes);
            self::assertStringContainsString('filename="original.bin"', $bytes);
        }
    }

    public function testMiddlewareCanInspectAndReplaceStreamsWithoutTruncatingSend(): void
    {
        $replacement = Utils::streamFor('replacement');
        $http = new class implements ClientInterface {
            public ?RequestInterface $request = null;
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                TestCase::assertSame('replacement', $request->getBody()->getContents());
                TestCase::assertSame('trace', $request->getHeaderLine('X-Trace'));
                return new Response(200, [], 'ok');
            }
        };
        $transport = new Psr18Transport($http);
        $transport->addMiddleware(new class($replacement) implements HttpMiddlewareInterface {
            public function __construct(private \Psr\Http\Message\StreamInterface $replacement) {}
            public function beforeRequest(RequestInterface $request): RequestInterface
            {
                $request->getBody()->read(2);
                $this->replacement->read(3);
                return $request->withBody($this->replacement)->withHeader('X-Trace', 'trace');
            }
            public function afterResponse(RequestInterface $request, ResponseInterface $response): ResponseInterface
            {
                return $response->withHeader('X-After', 'yes');
            }
        });
        $response = $transport->send('POST', 'https://example.test', [], Utils::streamFor('original'));
        self::assertSame($replacement, $http->request->getBody());
        self::assertSame('yes', $response->headers['X-After']);
        self::assertSame('ok', $response->body);
    }

    public function testLegacyCustomTransportsStillReceiveCompleteStringBodies(): void
    {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $client = StreamingProbeClient::connect('https://example.test', transport: $transport);
        $client->submit(new StreamingPayload((object) ['file' => new UploadedFile(Utils::streamFor("\x00\xff"), 2, UPLOAD_ERR_OK)]));
        $client->submit(null);
        $client->submit(new DTO());
        self::assertIsString($transport->sent[0]['body']);
        self::assertStringContainsString("\r\n\r\n\x00\xff\r\n", $transport->sent[0]['body']);
        self::assertNull($transport->sent[1]['body']);
        self::assertArrayNotHasKey('Content-Type', $transport->sent[1]['headers']);
        self::assertMatchesRegularExpression('/^--[a-f0-9]+--\r\n$/', $transport->sent[2]['body']);
    }

    public function testSharedFileCursorIsRejectedBeforeItCanTruncateASecondPart(): void
    {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $file = new UploadedFile(Utils::streamFor('bytes'), 5, UPLOAD_ERR_OK);
        try {
            StreamingProbeClient::connect('https://example.test', transport: $transport)->submit(
                new StreamingPayload((object) ['file' => $file, 'second' => $file]));
            self::fail('Shared stream cursor accepted.');
        } catch (\UnexpectedValueException) {
            self::assertSame([], $transport->sent);
        }
    }
}
