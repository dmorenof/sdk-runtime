<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\MultipartSerializer;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

class UploadPayload extends DTO
{
    public const array REQUIRED_FIELDS = ['file'];
    protected const array PROPERTY_MAP = ['upload-file' => 'file'];
    protected const array WIRE_PROPERTY_MAP = ['file' => 'upload-file'];
    protected const array READ_ONLY_FIELDS = ['internal'];
    public string|UploadedFileInterface $file;
    public string|UploadedFileInterface|null $optional;
    public string|UploadedFileInterface $internal;
}

class UploadProbeClient extends ApiClient
{
    public function submit(DTO $body, ?string $mime = null, string $media = 'multipart/form-data'): void
    {
        $this->executeOperation(['method' => 'POST', 'path' => '/uploads', 'success' => [204],
            'requestMediaType' => $media, 'bodyParts' => $media === 'multipart/form-data'
                ? ['upload-file' => ['contentType' => $mime, 'filename' => 'fallback.bin'],
                    'optional' => ['contentType' => null, 'filename' => 'optional.bin']] : []], body: $body);
    }
}

final class UploadedFileTest extends TestCase
{
    private static function upload(string $bytes = "\x00\xff\r\n", ?string $name = 'photo.png', ?string $mime = 'image/png'): UploadedFile
    {
        return new UploadedFile(Utils::streamFor($bytes), strlen($bytes), UPLOAD_ERR_OK, $name, $mime);
    }

    public function testNativeUploadUnionHydratesStringsUploadsAndNullableFields(): void
    {
        $file = self::upload();
        $body = new UploadPayload((object) ['upload-file' => $file, 'optional' => null]);
        self::assertSame($file, $body->file);
        self::assertNull($body->optional);
        self::assertSame('bytes', (new UploadPayload((object) ['upload-file' => 'bytes']))->file);
        foreach ([null, 1, (object) ['file' => 'bytes'], Utils::streamFor('bytes')] as $value) {
            try {
                new UploadPayload((object) ['upload-file' => $value]);
                self::fail('Invalid upload value accepted.');
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new class((object) ['value' => 1]) extends DTO { public int|string $value; };
    }

    public function testClientMetadataAndBinaryBytesUseGuzzleWithDirectionalFiltering(): void
    {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $client = UploadProbeClient::connect('https://example.test', transport: $transport);
        $body = new UploadPayload((object) ['upload-file' => self::upload(), 'optional' => null]);
        $body->internal = new UploadedFile(null, null, UPLOAD_ERR_PARTIAL);
        $client->submit($body);
        $sent = $transport->sent[0];
        self::assertStringContainsString('name="upload-file"; filename="photo.png"', $sent['body']);
        self::assertStringContainsString('Content-Type: image/png', $sent['body']);
        self::assertStringContainsString("\r\n\r\n\x00\xff\r\n\r\n", $sent['body']);
        self::assertStringNotContainsString('name="internal"', $sent['body']);
        self::assertStringNotContainsString('name="optional"', $sent['body']);
    }

    public function testReplayRewindsSeekableStreamsAndReadsNewAssignments(): void
    {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $client = UploadProbeClient::connect('https://example.test', transport: $transport);
        $upload = self::upload();
        $upload->getStream()->read(2);
        $body = new UploadPayload((object) ['upload-file' => $upload]);
        $client->submit($body);
        $client->submit($body);
        foreach ($transport->sent as $sent) {
            self::assertStringContainsString("\r\n\r\n\x00\xff\r\n\r\n", $sent['body']);
        }
        $body->file = self::upload('new bytes', 'next.txt', 'text/plain');
        $client->submit($body);
        self::assertStringContainsString('filename="next.txt"', $transport->sent[2]['body']);
        self::assertStringContainsString("\r\n\r\nnew bytes\r\n", $transport->sent[2]['body']);
        unset($body->file);
        try {
            $client->submit($body);
            self::fail('Missing required file accepted.');
        } catch (\UnexpectedValueException) {
            self::assertCount(3, $transport->sent);
        }
    }

    public function testNullMetadataFallsBackForStringsAndEmptyUnnamedUploads(): void
    {
        foreach (['bytes', self::upload('', null, null)] as $value) {
            $encoded = (string) MultipartSerializer::encode(new UploadPayload((object) ['upload-file' => $value]),
                ['upload-file' => ['contentType' => null, 'filename' => 'fallback.bin']]);
            self::assertStringContainsString('filename="fallback.bin"', $encoded);
            self::assertStringContainsString('Content-Type: application/octet-stream', $encoded);
        }
        $this->expectException(\InvalidArgumentException::class);
        MultipartSerializer::encode(new DTO(), ['text' => ['contentType' => null]]);
    }

    public function testDeclaredMimeIsEnforcedAndUnsafeOrFailedUploadsNeverReachHttp(): void
    {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $client = UploadProbeClient::connect('https://example.test', transport: $transport);
        $client->submit(new UploadPayload((object) ['upload-file' => self::upload()]), 'image/png');
        self::assertCount(1, $transport->sent);
        foreach ([self::upload('bytes', 'photo.jpg', 'image/jpeg'), self::upload('bytes', 'a"b', 'image/png'),
            self::upload('bytes', '0', 'image/png'), self::upload('bytes', "a\r\nb", 'image/png'),
            self::upload('bytes', 'safe.png', "image/png\r\nX: bad"), new UploadedFile(null, null, UPLOAD_ERR_PARTIAL)] as $file) {
            try {
                $client->submit(new UploadPayload((object) ['upload-file' => $file]), 'image/png');
                self::fail('Invalid upload accepted.');
            } catch (\UnexpectedValueException|\InvalidArgumentException) {
                self::assertCount(1, $transport->sent);
            }
        }
    }

    public function testUnreadableAndNonSeekableStreamsAreRejected(): void
    {
        $closed = Utils::streamFor('bytes');
        $closed->close();
        foreach ([$closed, new NoSeekStream(Utils::streamFor('bytes'))] as $stream) {
            $body = new UploadPayload((object) ['upload-file' => new UploadedFile($stream, null, UPLOAD_ERR_OK)]);
            try {
                MultipartSerializer::encode($body, ['upload-file' => ['contentType' => null, 'filename' => 'file.bin']]);
                self::fail('Unusable stream accepted.');
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }

    public function testUploadedFilesFailJsonEncodingAndJsonRequestsBeforeTransport(): void
    {
        $body = new UploadPayload((object) ['upload-file' => self::upload()]);
        try {
            json_encode($body, JSON_THROW_ON_ERROR);
            self::fail('Uploaded file silently encoded as JSON.');
        } catch (\UnexpectedValueException $error) {
            self::assertStringContainsString('multipart', $error->getMessage());
        }
        $transport = new FakeTransport(new HttpResponse(204, ''));
        try {
            UploadProbeClient::connect('https://example.test', transport: $transport)->submit($body, media: 'application/json');
            self::fail('Uploaded file sent as JSON.');
        } catch (\UnexpectedValueException) {
            self::assertSame([], $transport->sent);
        }
    }
}
